<?php

namespace App\Billing;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** User-initiated billing actions. Remote calls go through the gateway; webhooks confirm. */
class BillingService
{
    public function __construct(
        private readonly GatewayResolver $gateways,
        private readonly CouponService $coupons,
        private readonly ProrationCalculator $proration,
    ) {}

    public function checkout(User $user, PlanPrice $price, ?string $couponCode, ?CheckoutUrls $urls = null): CheckoutResult
    {
        $price->loadMissing('plan');
        if (! $price->is_active || ! $price->plan->is_active) {
            throw new GatewayException('This plan is no longer available.', 'price_unavailable');
        }

        $current = $user->activeSubscription();
        if ($current?->is_lifetime) {
            throw new GatewayException('You already have lifetime access.', 'already_lifetime', 409);
        }
        if ($current && ! $current->isManual() && ! $price->isLifetime()) {
            throw new GatewayException('You already have an active membership. Use “Change plan” instead.', 'already_subscribed', 409);
        }

        $gateway = $this->gateways->forPrice($price);
        $coupon = $couponCode ? $this->coupons->validate($couponCode, $user, $price, $gateway->gateway())['coupon'] : null;

        return $gateway->checkout($user, $price, $coupon, $urls ?? CheckoutUrls::default());
    }

    /** @return array<string, mixed> */
    public function previewChange(Subscription $subscription, PlanPrice $price): array
    {
        $this->assertChangeable($subscription, $price);

        return $this->proration->preview($subscription->loadMissing('plan', 'planPrice'), $price->loadMissing('plan'));
    }

    /** Upgrades apply now (gateway prorates); downgrades are scheduled for period end. */
    public function changePlan(Subscription $subscription, PlanPrice $price): Subscription
    {
        $this->assertChangeable($subscription, $price);
        $gateway = $this->gateways->for($subscription->gateway);

        // Choosing the current price again = withdraw a pending downgrade.
        if ($price->id === $subscription->plan_price_id) {
            if ($subscription->scheduled_plan_price_id) {
                $gateway->cancelScheduledChange($subscription);
                $subscription->forceFill(['scheduled_plan_price_id' => null, 'scheduled_change_at' => null])->save();
            }

            return $subscription;
        }

        $preview = $this->proration->preview($subscription->loadMissing('plan', 'planPrice'), $price->loadMissing('plan'));
        $immediate = $preview['direction'] === 'upgrade';

        $gateway->changePlan($subscription, $price, $immediate);

        DB::transaction(function () use ($subscription, $price, $immediate) {
            if ($immediate) {
                // Optimistic: the gateway accepted (and charged) the upgrade; webhooks will confirm periods.
                $subscription->forceFill([
                    'plan_id' => $price->plan_id, 'plan_price_id' => $price->id,
                    'scheduled_plan_price_id' => null, 'scheduled_change_at' => null,
                ])->save();
            } else {
                $subscription->forceFill([
                    'scheduled_plan_price_id' => $price->id,
                    'scheduled_change_at' => $subscription->current_period_end,
                ])->save();
            }
        });

        $subscription->user->unsetRelation('liveSubscriptions');

        return $subscription->refresh();
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $this->assertGatewayManaged($subscription);
        if ($subscription->is_lifetime) {
            throw new GatewayException('Lifetime memberships do not renew, so there is nothing to cancel.', 'lifetime', 409);
        }
        if ($subscription->cancel_at_period_end) {
            return $subscription;
        }

        $gateway = $this->gateways->for($subscription->gateway);
        $sendNow = $subscription->gateway === Gateway::Stripe || $this->withinRazorpayLeadWindow($subscription);

        if ($sendNow) {
            $gateway->cancelAtPeriodEnd($subscription);
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
            'gateway_cancel_requested_at' => $subscription->gateway === Gateway::Razorpay && $sendNow ? now() : $subscription->gateway_cancel_requested_at,
        ])->save();

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        $this->assertGatewayManaged($subscription);
        if (! $subscription->cancel_at_period_end) {
            return $subscription;
        }
        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            throw new GatewayException('This membership has already ended. Please subscribe again.', 'already_ended', 409);
        }

        $this->gateways->for($subscription->gateway)->resume($subscription);
        $subscription->forceFill(['cancel_at_period_end' => false, 'cancelled_at' => null])->save();

        return $subscription;
    }

    public function paymentMethodUrl(User $user, Subscription $subscription, string $returnUrl): ?string
    {
        $this->assertGatewayManaged($subscription);

        return $this->gateways->for($subscription->gateway)->paymentMethodUrl($user, $returnUrl);
    }

    /** Scheduler: send deferred Razorpay cancellations shortly before renewal. Returns count sent. */
    public function forwardRazorpayCancellations(): int
    {
        $count = 0;
        Subscription::where('gateway', Gateway::Razorpay)
            ->where('cancel_at_period_end', true)
            ->whereNull('gateway_cancel_requested_at')
            ->whereIn('status', SubscriptionStatus::live())
            ->where('current_period_end', '<=', now()->addHours((int) config('billing.razorpay_cancel_lead_hours')))
            ->each(function (Subscription $sub) use (&$count) {
                $this->gateways->for(Gateway::Razorpay)->cancelAtPeriodEnd($sub);
                $sub->forceFill(['gateway_cancel_requested_at' => now()])->save();
                $count++;
            });

        return $count;
    }

    private function withinRazorpayLeadWindow(Subscription $subscription): bool
    {
        return $subscription->current_period_end === null
            || $subscription->current_period_end->lessThanOrEqualTo(now()->addHours((int) config('billing.razorpay_cancel_lead_hours')));
    }

    private function assertGatewayManaged(Subscription $subscription): void
    {
        if ($subscription->isManual()) {
            throw new GatewayException('Complimentary memberships are managed by our team. Contact support to make changes.', 'manual_subscription', 409);
        }
        if (! $subscription->grantsAccess() && $subscription->status !== SubscriptionStatus::PastDue) {
            throw new GatewayException('This membership is not active.', 'not_active', 409);
        }
    }

    private function assertChangeable(Subscription $subscription, PlanPrice $price): void
    {
        $this->assertGatewayManaged($subscription);

        if ($subscription->is_lifetime || $price->isLifetime()) {
            throw new GatewayException('Lifetime plans are purchased separately at checkout.', 'lifetime', 409);
        }
        if (! $price->is_active) {
            throw new GatewayException('This plan is no longer available.', 'price_unavailable');
        }
        if ($subscription->cancel_at_period_end) {
            throw new GatewayException('Resume your membership before changing plans.', 'cancel_pending', 409);
        }
        if ($subscription->planPrice && strtoupper($subscription->planPrice->currency) !== strtoupper($price->currency)) {
            throw new GatewayException('You can only switch to plans in your billing currency.', 'currency_mismatch');
        }
    }
}
