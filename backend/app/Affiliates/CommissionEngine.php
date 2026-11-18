<?php

namespace App\Affiliates;

use App\Enums\PaymentStatus;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Referral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commissions on a referred customer's payments:
 *  - first payment + renewals paid within `recurring_months` of the first commissioned payment;
 *  - base = net of tax; percent or fixed (per currency), from the affiliate's override or tier;
 *  - optional level 2 for whoever recruited the affiliate;
 *  - held `hold_days` (pending → approved by release()); flagged referrals wait for admin review;
 *  - refunds / chargebacks reverse unpaid commissions and claw back paid ones.
 */
class CommissionEngine
{
    public function __construct(private readonly FraudGuard $fraud) {}

    /** @return list<Commission> created commissions */
    public function onPayment(Payment $payment): array
    {
        if ($payment->status !== PaymentStatus::Succeeded || $payment->amount <= 0) {
            return [];
        }
        $isSale = $payment->course_id !== null;
        if ((! $payment->subscription_id && ! $isSale) || ($isSale && ! config('affiliates.include_course_sales'))) {
            return [];
        }
        $referral = Referral::with('affiliate.user')->where('referred_user_id', $payment->user_id)->first();
        if (! $referral || $referral->status === 'rejected' || ! $referral->affiliate->isApproved()) {
            return [];
        }
        if (! $this->inWindow($referral, $payment)) {
            return [];
        }

        $base = max(0, $payment->amount - $payment->tax_amount);
        $paidAt = $payment->paid_at ?? now();
        if ($this->fraud->sharedPaymentMethod($referral->affiliate, $payment)) {
            $this->flag($referral, 'shared_payment_method');
        }

        return DB::transaction(function () use ($referral, $payment, $base, $paidAt) {
            $created = [];
            $terms = $referral->affiliate->commissionTerms();
            $created[] = $this->create($referral->affiliate, $referral, $payment, 1, $base, $terms, $paidAt);

            if (config('affiliates.level2.enabled')) {
                $parent = Referral::with('affiliate')->where('referred_user_id', $referral->affiliate->user_id)
                    ->whereIn('status', ['pending', 'converted'])->first();
                if ($parent && $parent->affiliate->isApproved() && $parent->affiliate_id !== $referral->affiliate_id) {
                    $created[] = $this->create($parent->affiliate, $referral, $payment, 2, $base,
                        ['type' => 'percent', 'rate' => (int) config('affiliates.level2.percent'), 'fixed' => null], $paidAt);
                }
            }
            if ($referral->status === 'pending') {
                $referral->forceFill(['status' => 'converted'])->save();
            }
            if (! $referral->converted_at) {
                $referral->forceFill(['converted_at' => $paidAt])->save();
            }

            return array_values(array_filter($created));
        });
    }

    /** Pending → approved once the hold period has passed, unless under review or the payment went bad. */
    public function release(): int
    {
        $count = 0;
        Commission::with('referral.affiliate', 'payment')->where('status', 'pending')->where('needs_review', false)
            ->where('available_at', '<=', now())->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $c) {
                    /** @var Commission $c */
                    $p = $c->payment;
                    if ($p && ($p->disputed_at || $p->status === PaymentStatus::Refunded)) {
                        continue; // reversal handles these
                    }
                    // Fingerprints can arrive after the payment (Stripe charge.succeeded) — check again before paying out.
                    if ($p && $this->fraud->sharedPaymentMethod($c->referral->affiliate, $p)) {
                        $this->flag($c->referral, 'shared_payment_method');

                        continue;
                    }
                    $c->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
                    $count++;
                }
            });

        return $count;
    }

    /** Full refund / chargeback: whole remaining commission; partial: proportional to the refunded part. */
    public function reverse(Payment $payment, int $refunded, bool $full, string $reason): void
    {
        DB::transaction(function () use ($payment, $refunded, $full, $reason) {
            $rows = Commission::where('payment_id', $payment->id)->where('kind', 'sale')->where('status', '!=', 'reversed')->lockForUpdate()->get();
            foreach ($rows as $c) {
                $remaining = $c->net() - (int) Commission::where('parent_id', $c->id)->sum(DB::raw('-amount'));
                $cut = $full ? $remaining : min($remaining, intdiv($c->amount * $refunded, max(1, $payment->amount)));
                if ($cut <= 0) {
                    continue;
                }
                $payout = $c->payout_id ? AffiliatePayout::find($c->payout_id) : null;

                if ($c->status === 'paid' || ($payout && $payout->status === 'paid')) {
                    // Already paid out: a negative line comes off the next payout.
                    Commission::create([
                        'affiliate_id' => $c->affiliate_id, 'referral_id' => $c->referral_id, 'payment_id' => null,
                        'level' => $c->level, 'kind' => 'clawback', 'parent_id' => $c->id, 'currency' => $c->currency,
                        'base_amount' => -$refunded, 'rate' => $c->rate, 'amount' => -$cut,
                        'status' => 'approved', 'available_at' => now(), 'approved_at' => now(), 'reversal_reason' => $reason,
                    ]);

                    continue;
                }
                $reversed = $c->reversed_amount + $cut;
                $c->forceFill([
                    'reversed_amount' => $reversed,
                    'status' => $reversed >= $c->amount ? 'reversed' : $c->status,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                ])->save();
                if ($payout && $payout->status === 'pending') {
                    app(Payouts\PayoutService::class)->recalculate($payout);
                }
            }
        });
    }

    /**
     * @param  array{type: string, rate: int|null, fixed: array<string, int>|null}  $terms
     */
    private function create(Affiliate $affiliate, Referral $referral, Payment $payment, int $level, int $base, array $terms, \DateTimeInterface $paidAt): ?Commission
    {
        $amount = $terms['type'] === 'fixed'
            ? min($base, (int) ($terms['fixed'][$payment->currency] ?? 0))
            : intdiv($base * (int) $terms['rate'], 100);
        if ($amount <= 0) {
            return null;
        }

        $existing = Commission::where(['payment_id' => $payment->id, 'affiliate_id' => $affiliate->id, 'level' => $level, 'kind' => 'sale'])->first();
        if ($existing) {
            return null; // webhook replays
        }

        return Commission::create([
            'affiliate_id' => $affiliate->id, 'referral_id' => $referral->id, 'payment_id' => $payment->id,
            'level' => $level, 'kind' => 'sale', 'currency' => $payment->currency,
            'base_amount' => $base, 'rate' => $terms['type'] === 'fixed' ? 'fixed' : $terms['rate'].'%', 'amount' => $amount,
            'status' => 'pending', 'needs_review' => $referral->isFlagged(),
            'available_at' => Carbon::parse($paidAt)->addDays((int) config('affiliates.hold_days')),
        ]);
    }

    private function inWindow(Referral $referral, Payment $payment): bool
    {
        $first = Commission::where('referral_id', $referral->id)->where('level', 1)->where('kind', 'sale')
            ->join('payments', 'payments.id', '=', 'commissions.payment_id')->min('payments.paid_at');
        if ($first === null) {
            return true; // first commissioned payment
        }
        $months = (int) config('affiliates.recurring_months');

        return $months > 0 && ($payment->paid_at ?? now())->lte(Carbon::parse((string) $first)->addMonths($months));
    }

    private function flag(Referral $referral, string $flag): void
    {
        $flags = array_values(array_unique([...($referral->flags ?? []), $flag]));
        $referral->forceFill(['status' => 'flagged', 'flags' => $flags])->save();
        Commission::where('referral_id', $referral->id)->where('status', 'pending')->update(['needs_review' => true]);
    }

    /** Admin review: approve (clear the hold) or reject (reverse everything for this referral). */
    public function review(Referral $referral, bool $approve, int $adminId): void
    {
        DB::transaction(function () use ($referral, $approve, $adminId) {
            $referral->forceFill([
                'status' => $approve ? ($referral->converted_at ? 'converted' : 'pending') : 'rejected',
                'reviewed_by' => $adminId, 'reviewed_at' => now(),
            ])->save();
            $q = Commission::where('referral_id', $referral->id)->whereIn('status', ['pending', 'approved'])->whereNull('payout_id');
            $approve
                ? $q->update(['needs_review' => false])
                : $q->update(['status' => 'reversed', 'reversed_at' => now(), 'reversal_reason' => 'rejected_in_review', 'needs_review' => false]);
        });
    }
}
