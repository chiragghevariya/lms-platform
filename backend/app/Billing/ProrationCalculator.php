<?php

namespace App\Billing;

use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Preview of what a plan change costs. Gateways compute the real charge
 * (Stripe proration / Razorpay "now" updates); this mirrors their time-based maths for the UI.
 */
class ProrationCalculator
{
    /** @return array{direction: string, effective: string, effective_at: string|null, credit: int, charge: int, amount_due_now: int, currency: string} */
    public function preview(Subscription $subscription, PlanPrice $newPrice, ?Carbon $now = null): array
    {
        $now ??= now();
        $current = $subscription->planPrice;
        $currentLevel = $subscription->plan->tier_level;
        $newLevel = $newPrice->plan->tier_level;
        $direction = $newLevel > $currentLevel || ($newLevel === $currentLevel && $newPrice->amount > (int) $current?->amount)
            ? 'upgrade' : 'downgrade';

        if ($direction === 'downgrade') {
            return [
                'direction' => $direction,
                'effective' => 'period_end',
                'effective_at' => $subscription->current_period_end?->toIso8601String(),
                'credit' => 0,
                'charge' => 0,
                'amount_due_now' => 0,
                'currency' => $newPrice->currency,
            ];
        }

        $fraction = $this->remainingFraction($subscription, $now);
        $credit = (int) round(((int) $current?->amount) * $fraction);
        $sameInterval = $current !== null && $current->interval === $newPrice->interval;
        $charge = $sameInterval ? (int) round($newPrice->amount * $fraction) : $newPrice->amount;

        return [
            'direction' => $direction,
            'effective' => 'now',
            'effective_at' => $now->toIso8601String(),
            'credit' => $credit,
            'charge' => $charge,
            'amount_due_now' => max(0, $charge - $credit),
            'currency' => $newPrice->currency,
        ];
    }

    private function remainingFraction(Subscription $subscription, Carbon $now): float
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if (! $start || ! $end || $end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $total = $start->diffInSeconds($end, true);
        $remaining = max(0, $now->diffInSeconds($end, false));

        return min(1.0, $remaining / $total);
    }
}
