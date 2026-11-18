<?php

namespace App\Instructors;

use App\Enums\PaymentStatus;
use App\Models\Course;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\PayoutStatement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monthly instructor earnings, per currency (amounts in minor units, rounded down; remainders stay
 * with the platform):
 *   pool                = net subscription revenue of the month × pool %
 *   subscription share  = pool × (pool-eligible watch seconds of the instructor's courses / all pool seconds)
 *   course sales        = net sale × the instructor's revenue share %
 * Net = amount paid − tax. Refunded / failed payments are excluded. Courses belong to their primary instructor.
 */
class EarningsService
{
    /**
     * Computes (without saving) every instructor's statement for the period.
     *
     * @return Collection<int, array{instructor_id: int, currency: string, pool_percent: int, revenue_share_percent: int,
     *     subscription_revenue: int, pool_amount: int, watch_seconds: int, total_watch_seconds: int,
     *     subscription_earnings: int, sales_earnings: int, total: int, lines: list<array<string, mixed>>}>
     */
    public function compute(string $period, ?int $onlyInstructor = null): Collection
    {
        [$from, $to] = $this->range($period);
        $poolPercent = (int) config('earnings.pool_percent');

        // Pool-eligible watch seconds per course this month.
        $watch = DB::table('course_watch_daily')->whereBetween('day', [$from->toDateString(), $to->copy()->subDay()->toDateString()])
            ->groupBy('course_id')->selectRaw('course_id, SUM(pool_seconds) as s')->havingRaw('SUM(pool_seconds) > 0')->pluck('s', 'course_id')
            ->map(fn ($s) => (int) $s);
        $totalWatch = (int) $watch->sum();
        $courses = Course::withTrashed()->whereIn('id', $watch->keys())->get(['id', 'title', 'instructor_id'])->keyBy('id');

        $payments = Payment::where('status', PaymentStatus::Succeeded)->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->get();
        $subsRevenue = $payments->whereNull('course_id')->whereNotNull('subscription_id')
            ->groupBy('currency')->map(fn ($p) => (int) $p->sum(fn (Payment $x) => self::net($x)));
        $sales = $payments->whereNotNull('course_id');
        $saleCourses = Course::withTrashed()->whereIn('id', $sales->pluck('course_id'))->get(['id', 'title', 'instructor_id'])->keyBy('id');

        $currencies = $subsRevenue->keys()->merge($sales->pluck('currency'))->unique();
        $instructorIds = $courses->pluck('instructor_id')->merge($saleCourses->pluck('instructor_id'))->unique()->map(fn ($id) => (int) $id);
        if ($onlyInstructor !== null) {
            $instructorIds = $instructorIds->filter(fn (int $id) => $id === $onlyInstructor);
        }
        $shares = InstructorProfile::whereIn('user_id', $instructorIds)->get()->keyBy('user_id');

        $out = collect();
        foreach ($instructorIds as $instructorId) {
            $share = $shares->get($instructorId)?->sharePercent() ?? (int) config('earnings.default_revenue_share_percent');
            foreach ($currencies as $currency) {
                $revenue = (int) ($subsRevenue[$currency] ?? 0);
                $pool = intdiv($revenue * $poolPercent, 100);
                $lines = [];

                foreach ($courses->where('instructor_id', $instructorId) as $course) {
                    $seconds = (int) $watch[$course->id];
                    $amount = $totalWatch > 0 ? intdiv($pool * $seconds, $totalWatch) : 0;
                    if ($pool > 0) {
                        $lines[] = ['type' => 'subscription_pool', 'course_id' => $course->id, 'payment_id' => null,
                            'description' => 'Member watch time: '.$course->title, 'watch_seconds' => $seconds, 'gross' => $pool, 'amount' => $amount];
                    }
                }
                foreach ($sales->where('currency', $currency) as $payment) {
                    $course = $saleCourses->get($payment->course_id);
                    if ($course?->instructor_id !== $instructorId) {
                        continue;
                    }
                    $net = self::net($payment);
                    $lines[] = ['type' => 'course_sale', 'course_id' => $course->id, 'payment_id' => $payment->id,
                        'description' => 'Course sale: '.$course->title, 'watch_seconds' => 0, 'gross' => $net, 'amount' => intdiv($net * $share, 100)];
                }
                if ($lines === []) {
                    continue;
                }

                $subscription = (int) collect($lines)->where('type', 'subscription_pool')->sum('amount');
                $salesTotal = (int) collect($lines)->where('type', 'course_sale')->sum('amount');
                $out->push([
                    'instructor_id' => $instructorId, 'currency' => $currency,
                    'pool_percent' => $poolPercent, 'revenue_share_percent' => $share,
                    'subscription_revenue' => $revenue, 'pool_amount' => $pool,
                    'watch_seconds' => (int) $courses->where('instructor_id', $instructorId)->sum(fn ($c) => $watch[$c->id]),
                    'total_watch_seconds' => $totalWatch,
                    'subscription_earnings' => $subscription, 'sales_earnings' => $salesTotal, 'total' => $subscription + $salesTotal,
                    'lines' => $lines,
                ]);
            }
        }

        return $out->values();
    }

    /**
     * Saves draft statements for a closed month. Finalized / paid statements are never changed.
     *
     * @return int statements written
     */
    public function generate(string $period): int
    {
        [, $to] = $this->range($period);
        if ($to->isFuture()) {
            throw new InvalidArgumentException("{$period} hasn't ended yet.");
        }

        return DB::transaction(function () use ($period) {
            $written = 0;
            $computed = $this->compute($period);
            $keep = [];
            foreach ($computed as $row) {
                $statement = PayoutStatement::where(['instructor_id' => $row['instructor_id'], 'period' => $period, 'currency' => $row['currency']])->lockForUpdate()->first();
                if ($statement && $statement->status !== 'draft') {
                    $keep[] = $statement->id;

                    continue;
                }
                $lines = $row['lines'];
                unset($row['lines']);
                $statement ??= new PayoutStatement(['instructor_id' => $row['instructor_id'], 'period' => $period, 'currency' => $row['currency']]);
                $statement->fill($row + ['status' => 'draft'])->save();
                $statement->lines()->delete();
                $statement->lines()->createMany($lines);
                $keep[] = $statement->id;
                $written++;
            }
            // Drafts that no longer have earnings (e.g. a refund) are removed.
            PayoutStatement::where('period', $period)->where('status', 'draft')->whereNotIn('id', $keep)->delete();

            return $written;
        });
    }

    public function finalize(string $period): int
    {
        return PayoutStatement::where('period', $period)->where('status', 'draft')->update(['status' => 'finalized', 'finalized_at' => now()]);
    }

    public function markPaid(PayoutStatement $statement, User $admin, string $reference): PayoutStatement
    {
        abort_unless($statement->status === 'finalized', 409, 'Finalize the statement before marking it paid.');
        $statement->forceFill(['status' => 'paid', 'paid_at' => now(), 'paid_reference' => $reference, 'paid_by' => $admin->id])->save();

        return $statement;
    }

    /** Net of tax, minus the refunded share of a partly refunded payment. */
    public static function net(Payment $p): int
    {
        $net = $p->amount - $p->tax_amount;

        return $p->refunded_amount > 0 && $p->amount > 0 ? intdiv($net * ($p->amount - $p->refunded_amount), $p->amount) : $net;
    }

    /** @return array{0: Carbon, 1: Carbon} [start, end) in UTC */
    public function range(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException('Period must look like 2026-10.');
        }
        $from = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', 'UTC');

        return [$from, $from->copy()->addMonth()];
    }
}
