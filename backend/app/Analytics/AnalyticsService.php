<?php

namespace App\Analytics;

use App\Enums\BillingInterval;
use App\Enums\Gateway;
use App\Enums\LiveSessionStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Commission;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\Enrollment;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\MetricSnapshot;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Business metrics for the admin dashboard. Every money figure is derived from the
 * `payments` table (minor units, one currency at a time — no FX conversion), so the
 * numbers reconcile with it exactly. Results are cached per (report, range, currency) and
 * invalidated by bumping a generation counter whenever a payment is recorded or refunded.
 *
 * Definitions (all in the reporting timezone, ranges inclusive):
 *  - gross: sum of `amount` of payments paid in the range (succeeded or later refunded)
 *  - refunds: sum of `refunded_amount` of payments refunded in the range
 *  - net revenue: gross − refunds (tax included; `tax` is shown separately)
 *  - MRR: for each paying, renewing subscription (active / past_due, not lifetime, not
 *    complimentary) its latest paid amount net of tax, normalised to a month (yearly ÷ 12)
 *  - paying members: distinct users behind those subscriptions; ARPU = MRR ÷ paying members
 *  - churned: users whose last paid subscription ended in the range with nothing else live
 *  - churn rate: churned ÷ paying members at the start of the range
 *  - LTV: ARPU ÷ monthly churn rate (churn rate scaled to 30 days)
 *  - trial conversion: trials started in the range that later had a paid payment
 */
class AnalyticsService
{
    public const PAID = [PaymentStatus::Succeeded, PaymentStatus::Refunded];

    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('analytics.timezone', 'UTC');
    }

    // ---------------------------------------------------------------- caching

    public function cache(): Repository
    {
        return Cache::store(config('analytics.cache_store') ?: null);
    }

    /** Called on payment/refund events: every cached report is recomputed on next read. */
    public function invalidate(): void
    {
        $this->cache()->forever('analytics:gen', (int) $this->cache()->get('analytics:gen', 0) + 1);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    private function remember(string $key, callable $compute): mixed
    {
        $gen = (int) $this->cache()->get('analytics:gen', 0);

        $cacheKey = "analytics:{$gen}:{$key}";
        $hit = $this->cache()->get($cacheKey);
        if ($hit !== null) {
            return $hit;
        }
        $value = $compute();
        $this->cache()->put($cacheKey, $value, now()->addMinutes((int) config('analytics.cache_ttl_minutes', 60)));

        return $value;
    }

    /** @return array{0: Carbon, 1: Carbon} UTC bounds for local dates [from 00:00, to 23:59:59] */
    public function bounds(string $from, string $to): array
    {
        return [
            Carbon::parse($from, $this->tz)->startOfDay()->utc(),
            Carbon::parse($to, $this->tz)->endOfDay()->utc(),
        ];
    }

    public function today(): string
    {
        return now($this->tz)->toDateString();
    }

    // ---------------------------------------------------------------- revenue

    /** @return Builder<Payment> */
    private function paid(string $currency): Builder
    {
        return Payment::query()->whereIn('status', self::PAID)->whereNotNull('paid_at')->where('currency', $currency);
    }

    /**
     * Revenue totals, split and series for a range.
     *
     * @return array<string, mixed>
     */
    public function revenue(string $from, string $to, string $currency): array
    {
        return $this->remember("revenue:{$from}:{$to}:{$currency}", function () use ($from, $to, $currency) {
            [$start, $end] = $this->bounds($from, $to);

            $paid = $this->paid($currency)->whereBetween('paid_at', [$start, $end]);
            $refunded = Payment::where('currency', $currency)->where('refunded_amount', '>', 0)->whereBetween('refunded_at', [$start, $end]);

            $gross = (int) (clone $paid)->sum('amount');
            $refunds = (int) (clone $refunded)->sum('refunded_amount');

            $monthly = Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92;
            $bucket = fn (Carbon $at) => $at->copy()->setTimezone($this->tz)->format($monthly ? 'Y-m' : 'Y-m-d');
            $series = [];
            for ($d = Carbon::parse($from, $this->tz); $d->lte(Carbon::parse($to, $this->tz)); $monthly ? $d->addMonthNoOverflow() : $d->addDay()) {
                $series[$d->format($monthly ? 'Y-m' : 'Y-m-d')] = ['membership' => 0, 'courses' => 0, 'refunds' => 0];
            }

            (clone $paid)->select(['id', 'paid_at', 'amount', 'subscription_id', 'course_id'])->lazyById(1000)
                ->each(function (Payment $p) use (&$series, $bucket) {
                    $key = $bucket($p->paid_at ?? now());
                    if (isset($series[$key])) {
                        $series[$key][$p->course_id ? 'courses' : 'membership'] += $p->amount;
                    }
                });
            (clone $refunded)->select(['id', 'refunded_at', 'refunded_amount'])->lazyById(1000)
                ->each(function (Payment $p) use (&$series, $bucket) {
                    $key = $bucket($p->refunded_at ?? now());
                    if (isset($series[$key])) {
                        $series[$key]['refunds'] += $p->refunded_amount;
                    }
                });

            return [
                'currency' => $currency,
                'gross' => $gross,
                'refunds' => $refunds,
                'net' => $gross - $refunds,
                'tax' => (int) (clone $paid)->sum('tax_amount'),
                'membership' => (int) (clone $paid)->whereNull('course_id')->sum('amount'),
                'courses' => (int) (clone $paid)->whereNotNull('course_id')->sum('amount'),
                'payments' => (clone $paid)->count(),
                'interval' => $monthly ? 'month' : 'day',
                'series' => collect($series)->map(fn ($v, $k) => ['period' => $k, ...$v, 'net' => $v['membership'] + $v['courses'] - $v['refunds']])->values()->all(),
                'by_gateway' => (clone $paid)->select('gateway', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as payments'))
                    ->groupBy('gateway')->orderByDesc('total')->get()
                    ->map(fn ($r) => ['gateway' => $r->gateway->value, 'total' => (int) $r->getAttribute('total'), 'payments' => (int) $r->getAttribute('payments')])->all(),
                'by_country' => (clone $paid)->select(DB::raw("COALESCE(billing_country, '') as country"), DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as payments'))
                    ->groupBy(DB::raw("COALESCE(billing_country, '')"))->orderByDesc('total')->limit(15)->get()
                    ->map(fn ($r) => ['country' => $r->getAttribute('country') ?: null, 'total' => (int) $r->getAttribute('total'), 'payments' => (int) $r->getAttribute('payments')])->all(),
            ];
        });
    }

    // ---------------------------------------------------------------- membership

    /**
     * Paying, renewing subscriptions right now with their monthly value.
     *
     * @return list<array{user_id: int, subscription_id: int, monthly: int}>
     */
    private function payingSubscriptions(string $currency, ?Carbon $at = null): array
    {
        $at ??= now();
        $subs = Subscription::query()
            ->with('planPrice:id,interval,currency')
            ->where('gateway', '!=', Gateway::Manual)
            ->where('is_lifetime', false)
            ->where('created_at', '<=', $at)
            ->when(
                $at->isToday() || $at->isFuture(),
                fn ($q) => $q->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue]),
                // historical: anything that had started and not yet ended at that moment
                fn ($q) => $q->whereNotIn('status', [SubscriptionStatus::Incomplete])->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at)),
            )
            ->get(['id', 'user_id', 'plan_price_id', 'status']);

        if ($subs->isEmpty()) {
            return [];
        }

        // Latest paid membership payment per subscription (before $at).
        $latest = Payment::query()->whereIn('subscription_id', $subs->pluck('id'))->whereIn('status', self::PAID)
            ->where('currency', $currency)->whereNull('course_id')->where('paid_at', '<=', $at)
            ->orderBy('paid_at')->get(['id', 'subscription_id', 'amount', 'tax_amount', 'plan_price_id'])
            ->keyBy('subscription_id');

        $out = [];
        foreach ($subs as $s) {
            $p = $latest->get($s->id);
            if (! $p) {
                continue; // trialing / never paid / other currency
            }
            $net = $p->amount - $p->tax_amount;
            $out[] = [
                'user_id' => $s->user_id,
                'subscription_id' => $s->id,
                'monthly' => $s->planPrice?->interval === BillingInterval::Year ? intdiv($net + 6, 12) : $net,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function membership(string $from, string $to, string $currency): array
    {
        return $this->remember("membership:{$from}:{$to}:{$currency}", function () use ($from, $to, $currency) {
            [$start, $end] = $this->bounds($from, $to);
            $paying = $this->payingSubscriptions($currency);
            $mrr = array_sum(array_column($paying, 'monthly'));
            $payingUsers = count(array_unique(array_column($paying, 'user_id')));

            $atStart = count(array_unique(array_column($this->payingSubscriptions($currency, $start), 'user_id')));

            // First paid membership payment per user (any currency) → "new" when it falls in range.
            $firsts = Payment::query()->whereIn('status', self::PAID)->whereNull('course_id')->whereNotNull('subscription_id')
                ->where('amount', '>', 0)->groupBy('user_id')->select('user_id', DB::raw('MIN(paid_at) as first_paid'));
            $new = DB::query()->fromSub($firsts, 'f')->whereBetween('first_paid', [$start, $end])->count();

            $churned = $this->churnedUsers($start, $end);
            $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
            $churnRate = $atStart > 0 ? $churned / $atStart : null;
            $monthlyChurn = $churnRate !== null ? min(1, $churnRate * 30 / $days) : null;
            $arpu = $payingUsers > 0 ? intdiv($mrr, $payingUsers) : 0;

            $trials = Subscription::query()->whereNotNull('trial_ends_at')->whereBetween('created_at', [$start, $end])->pluck('id');
            $converted = $trials->isEmpty() ? 0 : Payment::whereIn('subscription_id', $trials)->whereIn('status', self::PAID)
                ->where('amount', '>', 0)->distinct()->count('subscription_id');

            return [
                'currency' => $currency,
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'paying_members' => $payingUsers,
                'paying_at_start' => $atStart,
                'new_members' => $new,
                'churned_members' => $churned,
                'churn_rate' => $churnRate !== null ? round($churnRate, 4) : null,
                'monthly_churn_rate' => $monthlyChurn !== null ? round($monthlyChurn, 4) : null,
                'arpu' => $arpu,
                'ltv' => $monthlyChurn ? (int) round($arpu / $monthlyChurn) : null,
                'trials_started' => $trials->count(),
                'trials_converted' => $converted,
                'trial_conversion' => $trials->count() > 0 ? round($converted / $trials->count(), 4) : null,
                'active_by_tier' => $this->activeByTier(),
            ];
        });
    }

    /** Users whose paid subscription ended in [start, end] with no other paid one live afterwards. */
    private function churnedUsers(Carbon $start, Carbon $end): int
    {
        $ended = Subscription::query()->where('gateway', '!=', Gateway::Manual)
            ->whereIn('status', [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired])
            ->whereBetween('ended_at', [$start, $end])
            ->whereExists(fn ($q) => $q->from('payments')->whereColumn('payments.subscription_id', 'subscriptions.id')->whereIn('payments.status', ['succeeded', 'refunded'])->where('payments.amount', '>', 0))
            ->get(['id', 'user_id', 'ended_at']);

        return $ended->groupBy('user_id')->filter(function ($subs, $userId) {
            $lastEnd = $subs->max('ended_at');

            return ! Subscription::where('user_id', $userId)->whereNotIn('id', $subs->pluck('id'))
                ->where('gateway', '!=', Gateway::Manual)
                ->where(fn ($q) => $q->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
                    ->orWhere('ended_at', '>', $lastEnd))
                ->exists();
        })->count();
    }

    /**
     * Members whose highest access-granting subscription is each tier, split paid / complimentary.
     *
     * @return list<array{tier: int, name: string, members: int, paying: int, complimentary: int}>
     */
    public function activeByTier(): array
    {
        $subs = Subscription::query()->with('plan:id,tier_level,name')
            ->whereIn('status', SubscriptionStatus::live())
            ->get(['id', 'user_id', 'plan_id', 'gateway', 'status', 'is_lifetime', 'current_period_start', 'current_period_end', 'past_due_since']);

        $best = [];
        foreach ($subs as $s) {
            if (! $s->grantsAccess()) {
                continue;
            }
            $tier = $s->plan->tier_level;
            if (! isset($best[$s->user_id]) || $best[$s->user_id]['tier'] < $tier || ($best[$s->user_id]['tier'] === $tier && $best[$s->user_id]['manual'])) {
                $best[$s->user_id] = ['tier' => $tier, 'manual' => $s->isManual(), 'name' => $s->plan->name];
            }
        }

        return collect($best)->groupBy('tier')->map(fn ($rows, $tier) => [
            'tier' => (int) $tier,
            'name' => (string) $rows->first()['name'],
            'members' => $rows->count(),
            'paying' => $rows->where('manual', false)->count(),
            'complimentary' => $rows->where('manual', true)->count(),
        ])->sortBy('tier')->values()->all();
    }

    // ---------------------------------------------------------------- funnel & cohorts

    /**
     * Signups in the range and how far they got.
     *
     * @return list<array{step: string, label: string, count: int}>
     */
    public function funnel(string $from, string $to): array
    {
        return $this->remember("funnel:{$from}:{$to}", function () use ($from, $to) {
            [$start, $end] = $this->bounds($from, $to);
            $users = User::query()->whereBetween('created_at', [$start, $end]);
            $ids = (clone $users)->select('id');

            return [
                ['step' => 'signed_up', 'label' => 'Signed up', 'count' => (clone $users)->count()],
                ['step' => 'verified', 'label' => 'Verified email', 'count' => (clone $users)->whereNotNull('email_verified_at')->count()],
                ['step' => 'learning', 'label' => 'Started a course', 'count' => Enrollment::whereIn('user_id', $ids)->distinct()->count('user_id')],
                ['step' => 'paid', 'label' => 'Paid', 'count' => Payment::whereIn('user_id', $ids)->whereIn('status', self::PAID)->where('amount', '>', 0)->distinct()->count('user_id')],
            ];
        });
    }

    /**
     * Monthly cohorts by first paid membership payment; each cell = share of the cohort with a
     * paid membership period overlapping that month.
     *
     * @return list<array{cohort: string, size: int, retention: list<float|null>}>
     */
    public function cohorts(int $months = 12): array
    {
        return $this->remember("cohorts:{$months}:".$this->today(), function () use ($months) {
            $first = now($this->tz)->startOfMonth()->subMonths($months - 1);
            $payments = Payment::query()->whereIn('status', self::PAID)->whereNull('course_id')->whereNotNull('subscription_id')
                ->where('amount', '>', 0)->whereNotNull('paid_at')
                ->orderBy('paid_at')->get(['user_id', 'paid_at', 'period_start', 'period_end', 'status', 'refunded_amount', 'amount']);

            $cohortOf = [];
            $activeMonths = [];
            foreach ($payments as $p) {
                $paid = $p->paid_at?->copy()->setTimezone($this->tz) ?? now($this->tz);
                $cohortOf[$p->user_id] ??= $paid->format('Y-m');
                if ($p->status === PaymentStatus::Refunded) {
                    continue; // fully refunded periods don't count as retained
                }
                $from = ($p->period_start ?? $p->paid_at ?? now())->copy()->setTimezone($this->tz)->startOfMonth();
                $to = ($p->period_end ?? $p->paid_at ?? now())->copy()->setTimezone($this->tz)->subSecond();
                for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
                    $activeMonths[$p->user_id][$m->format('Y-m')] = true;
                }
            }

            $rows = [];
            $nowKey = now($this->tz)->format('Y-m');
            for ($c = $first->copy(); $c->format('Y-m') <= $nowKey; $c->addMonthNoOverflow()) {
                $key = $c->format('Y-m');
                $members = array_keys(array_filter($cohortOf, fn ($k) => $k === $key));
                $retention = [];
                for ($k = 0, $m = $c->copy(); $m->format('Y-m') <= $nowKey; $k++, $m->addMonthNoOverflow()) {
                    $mk = $m->format('Y-m');
                    $retention[] = $members ? round(count(array_filter($members, fn ($u) => isset($activeMonths[$u][$mk]))) / count($members), 4) : null;
                }
                $rows[] = ['cohort' => $key, 'size' => count($members), 'retention' => $retention];
            }

            return $rows;
        });
    }

    // ---------------------------------------------------------------- learning, live, affiliates

    /** @return array<string, mixed> */
    public function learning(string $from, string $to, string $currency): array
    {
        return $this->remember("learning:{$from}:{$to}:{$currency}", function () use ($from, $to, $currency) {
            [$start, $end] = $this->bounds($from, $to);
            $dayFrom = Carbon::parse($from)->toDateString();
            $dayTo = Carbon::parse($to)->toDateString();

            $watch = DB::table('course_watch_daily')->whereBetween('day', [$dayFrom, $dayTo])
                ->groupBy('course_id')->select('course_id', DB::raw('SUM(seconds) as seconds'), DB::raw('COUNT(DISTINCT user_id) as learners'))
                ->orderByDesc('seconds')->limit(10)->get()->keyBy('course_id');

            $courseIds = $watch->keys()->all();
            $enrolled = Enrollment::whereIn('course_id', $courseIds)->groupBy('course_id')->select('course_id', DB::raw('COUNT(*) as n'))->pluck('n', 'course_id');
            $completed = CourseProgress::whereIn('course_id', $courseIds)->whereNotNull('completed_at')->groupBy('course_id')->select('course_id', DB::raw('COUNT(*) as n'))->pluck('n', 'course_id');
            $sales = $this->paid($currency)->whereIn('course_id', $courseIds)->whereBetween('paid_at', [$start, $end])
                ->groupBy('course_id')->select('course_id', DB::raw('SUM(amount) as total'))->pluck('total', 'course_id');
            $courses = Course::with('instructor:id,name')->whereIn('id', $courseIds)->get(['id', 'uuid', 'title', 'slug', 'instructor_id', 'rating_avg'])->keyBy('id');

            $top = $watch->map(function ($w, $id) use ($courses, $enrolled, $completed, $sales) {
                $course = $courses->get($id);
                $n = (int) ($enrolled[$id] ?? 0);

                return [
                    'id' => $course?->uuid,
                    'title' => $course?->title,
                    'slug' => $course?->slug,
                    'instructor' => $course?->instructor?->name,
                    'watch_seconds' => (int) $w->seconds,
                    'learners' => (int) $w->learners,
                    'enrollments' => $n,
                    'completion_rate' => $n > 0 ? round(((int) ($completed[$id] ?? 0)) / $n, 4) : null,
                    'sales' => (int) ($sales[$id] ?? 0),
                    'rating' => $course ? (float) $course->rating_avg : null,
                ];
            })->values()->all();

            $enrollmentsInRange = Enrollment::whereBetween('started_at', [$start, $end])->count();
            $completionsInRange = CourseProgress::whereBetween('completed_at', [$start, $end])->count();
            $allEnrolled = Enrollment::count();

            $sessions = LiveSession::query()->where('status', LiveSessionStatus::Ended)->whereBetween('starts_at', [$start, $end])->pluck('id');
            $registered = $sessions->isEmpty() ? 0 : LiveRegistration::whereIn('live_session_id', $sessions)->count();
            $attended = $sessions->isEmpty() ? 0 : LiveRegistration::whereIn('live_session_id', $sessions)->where('attended', true)->count();

            return [
                'watch_seconds' => (int) DB::table('course_watch_daily')->whereBetween('day', [$dayFrom, $dayTo])->sum('seconds'),
                'active_learners' => (int) DB::table('course_watch_daily')->whereBetween('day', [$dayFrom, $dayTo])->distinct()->count('user_id'),
                'enrollments' => $enrollmentsInRange,
                'completions' => $completionsInRange,
                'completion_rate' => $allEnrolled > 0 ? round(CourseProgress::whereNotNull('completed_at')->count() / $allEnrolled, 4) : null,
                'top_courses' => $top,
                'live' => [
                    'sessions' => $sessions->count(),
                    'registrations' => $registered,
                    'attended' => $attended,
                    'attendance_rate' => $registered > 0 ? round($attended / $registered, 4) : null,
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function affiliates(string $from, string $to, string $currency): array
    {
        return $this->remember("affiliates:{$from}:{$to}:{$currency}", function () use ($from, $to, $currency) {
            [$start, $end] = $this->bounds($from, $to);
            $commissions = Commission::where('currency', $currency)->whereBetween('created_at', [$start, $end]);
            $referredRevenue = (int) $this->paid($currency)->whereBetween('paid_at', [$start, $end])
                ->whereIn('id', Commission::where('level', 1)->where('kind', 'sale')->select('payment_id'))->sum('amount');
            $earned = (int) (clone $commissions)->sum(DB::raw('amount - reversed_amount'));
            $net = $this->revenue($from, $to, $currency)['net'];

            return [
                'referred_revenue' => $referredRevenue,
                'commissions' => $earned,
                'share_of_revenue' => $net > 0 ? round($earned / $net, 4) : null,
                'referred_share' => $net > 0 ? round($referredRevenue / $net, 4) : null,
                'paid_out' => (int) Commission::where('currency', $currency)->where('status', 'paid')->whereBetween('updated_at', [$start, $end])->sum('amount'),
            ];
        });
    }

    /**
     * Headline numbers for the dashboard cards.
     *
     * @return array<string, mixed>
     */
    public function summary(string $from, string $to, string $currency): array
    {
        $today = $this->today();
        $m = $this->membership($from, $to, $currency);

        return [
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'mrr' => $m['mrr'],
            'arr' => $m['arr'],
            'active_members' => array_sum(array_column($m['active_by_tier'], 'members')),
            'paying_members' => $m['paying_members'],
            'churn_rate' => $m['churn_rate'],
            'revenue_today' => $this->revenue($today, $today, $currency)['net'],
            'revenue_range' => $this->revenue($from, $to, $currency)['net'],
            'mrr_trend' => $this->mrrTrend($currency, 30),
        ];
    }

    /**
     * MRR history from the nightly snapshots (plus today, live).
     *
     * @return list<array{day: string, mrr: int}>
     */
    public function mrrTrend(string $currency, int $days): array
    {
        $rows = MetricSnapshot::where('metric', 'mrr')->where('dimension', $currency)
            ->where('day', '>=', now($this->tz)->subDays($days)->toDateString())->orderBy('day')->get(['day', 'value'])
            ->map(fn ($r) => ['day' => $r->day->toDateString(), 'mrr' => $r->value])->all();
        $rows[] = ['day' => $this->today(), 'mrr' => array_sum(array_column($this->payingSubscriptions($currency), 'monthly'))];

        return array_values(collect($rows)->keyBy('day')->all());
    }

    /**
     * Currencies with any paid payment (dashboard switcher), default first.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        $default = (string) config('billing.default_currency', 'INR');
        $found = Payment::whereIn('status', self::PAID)->distinct()->pluck('currency')->all();

        return array_values(array_unique([$default, ...$found]));
    }

    /** Nightly: persist yesterday's point-in-time metrics. */
    public function snapshot(?Carbon $day = null): int
    {
        $day ??= now($this->tz)->subDay();
        $at = $day->copy()->setTimezone($this->tz)->endOfDay()->utc();
        $written = 0;
        foreach ($this->currencies() as $currency) {
            $paying = $this->payingSubscriptions($currency, $at);
            foreach (['mrr' => array_sum(array_column($paying, 'monthly')), 'paying_members' => count(array_unique(array_column($paying, 'user_id')))] as $metric => $value) {
                MetricSnapshot::updateOrCreate(['day' => $day->toDateString(), 'metric' => $metric, 'dimension' => $currency], ['value' => $value]);
                $written++;
            }
        }
        foreach ($this->activeByTier() as $tier) {
            MetricSnapshot::updateOrCreate(['day' => $day->toDateString(), 'metric' => 'members', 'dimension' => 'tier:'.$tier['tier']], ['value' => $tier['members']]);
            $written++;
        }

        return $written;
    }
}
