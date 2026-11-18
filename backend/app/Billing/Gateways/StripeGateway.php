<?php

namespace App\Billing\Gateways;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Billing\GatewayException;
use App\Billing\PaymentGateway;
use App\Enums\CouponType;
use App\Enums\Gateway;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Stripe via Laravel Cashier: Cashier creates/reuses the Stripe customer and builds
 * Checkout Sessions + Billing Portal links; subscription changes use the Stripe client.
 */
class StripeGateway implements PaymentGateway
{
    public function gateway(): Gateway
    {
        return Gateway::Stripe;
    }

    public function checkout(User $user, PlanPrice $price, ?Coupon $coupon, CheckoutUrls $urls): CheckoutResult
    {
        if (! $price->stripe_price_id) {
            throw new GatewayException('This plan is not available for card payments yet.', 'price_not_configured');
        }

        $metadata = array_filter([
            'user_uuid' => $user->uuid,
            'plan_price_uuid' => $price->uuid,
            'coupon_uuid' => $coupon?->uuid,
        ]);

        $session = [
            'mode' => $price->isLifetime() ? 'payment' : 'subscription',
            'success_url' => $urls->success.'?gateway=stripe&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $urls->cancel.'?gateway=stripe',
            'client_reference_id' => $user->uuid,
            'metadata' => $metadata,
        ];

        if ($price->isLifetime()) {
            $session['payment_intent_data'] = ['metadata' => $metadata];
        } else {
            $session['subscription_data'] = array_filter([
                'metadata' => $metadata,
                'trial_period_days' => $price->trial_days > 0 && ! $user->subscriptions()->withTrashed()->exists() ? $price->trial_days : null,
            ]);
        }

        if ($coupon) {
            $session['discounts'] = [['coupon' => $this->stripeCouponId($coupon)]];
        }

        try {
            $checkout = $user->checkout([$price->stripe_price_id => 1], $session);
        } catch (ApiErrorException $e) {
            report($e);
            throw new GatewayException('Could not start card checkout. Please try again.');
        }

        return new CheckoutResult(Gateway::Stripe, redirectUrl: (string) $checkout->asStripeCheckoutSession()->url);
    }

    public function checkoutCourse(User $user, Course $course, CheckoutUrls $urls): CheckoutResult
    {
        $metadata = ['kind' => 'course', 'user_uuid' => $user->uuid, 'course_uuid' => $course->uuid];

        try {
            $checkout = $user->checkout([[
                'price_data' => [
                    'currency' => strtolower((string) $course->currency),
                    'unit_amount' => (int) $course->price_amount,
                    'product_data' => ['name' => $course->title],
                ],
                'quantity' => 1,
            ]], [
                'mode' => 'payment',
                'success_url' => $urls->success.'&gateway=stripe&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $urls->cancel,
                'client_reference_id' => $user->uuid,
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata],
            ]);
        } catch (ApiErrorException $e) {
            report($e);
            throw new GatewayException('Could not start card checkout. Please try again.');
        }

        return new CheckoutResult(Gateway::Stripe, redirectUrl: (string) $checkout->asStripeCheckoutSession()->url);
    }

    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $immediate): void
    {
        if (! $newPrice->stripe_price_id) {
            throw new GatewayException('That plan is not available for card payments yet.', 'price_not_configured');
        }

        $this->call(function (StripeClient $stripe) use ($subscription, $newPrice, $immediate) {
            $remote = $stripe->subscriptions->retrieve((string) $subscription->gateway_subscription_id);
            $stripe->subscriptions->update($remote->id, [
                'items' => [['id' => $remote->items->data[0]->id, 'price' => $newPrice->stripe_price_id]],
                // Upgrades: charge the prorated difference now. Downgrades: new price from next renewal,
                // access stays on the current plan until period end (see SubscriptionSync).
                'proration_behavior' => $immediate ? 'always_invoice' : 'none',
                'payment_behavior' => $immediate ? 'error_if_incomplete' : 'allow_incomplete',
                'metadata' => ['plan_price_uuid' => $newPrice->uuid, 'user_uuid' => $subscription->user->uuid],
            ]);
        });
    }

    public function cancelScheduledChange(Subscription $subscription): void
    {
        $current = $subscription->planPrice;
        if (! $current?->stripe_price_id) {
            return;
        }

        $this->call(function (StripeClient $stripe) use ($subscription, $current) {
            $remote = $stripe->subscriptions->retrieve((string) $subscription->gateway_subscription_id);
            $stripe->subscriptions->update($remote->id, [
                'items' => [['id' => $remote->items->data[0]->id, 'price' => $current->stripe_price_id]],
                'proration_behavior' => 'none',
                'metadata' => ['plan_price_uuid' => $current->uuid, 'user_uuid' => $subscription->user->uuid],
            ]);
        });
    }

    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        $this->call(fn (StripeClient $s) => $s->subscriptions->update((string) $subscription->gateway_subscription_id, ['cancel_at_period_end' => true]));
    }

    public function resume(Subscription $subscription): void
    {
        $this->call(fn (StripeClient $s) => $s->subscriptions->update((string) $subscription->gateway_subscription_id, ['cancel_at_period_end' => false]));
    }

    public function cancelNow(Subscription $subscription): void
    {
        $this->call(fn (StripeClient $s) => $s->subscriptions->cancel((string) $subscription->gateway_subscription_id));
    }

    public function paymentMethodUrl(User $user, string $returnUrl): ?string
    {
        if (! $user->stripe_id) {
            return null;
        }

        return $this->call(fn () => $user->billingPortalUrl($returnUrl));
    }

    public function refund(Payment $payment, int $amount, string $reason): string
    {
        // Subscription invoices keep the PaymentIntent in gateway_reference; one-time course
        // checkouts store it as the payment id itself.
        $intent = collect([$payment->gateway_reference, $payment->gateway_payment_id])
            ->first(fn ($id) => is_string($id) && str_starts_with($id, 'pi_'));
        if (! $intent) {
            throw new GatewayException('This payment has no Stripe PaymentIntent on record. Refund it in the Stripe dashboard; the webhook will update it here.', 'refund_unsupported');
        }

        /** @var Refund $refund */
        $refund = $this->call(fn (StripeClient $s) => $s->refunds->create([
            'payment_intent' => $intent,
            'amount' => $amount,
            'reason' => 'requested_by_customer',
            'metadata' => ['payment_uuid' => $payment->uuid, 'note' => mb_substr($reason, 0, 450)],
        ], ['idempotency_key' => 'refund-'.$payment->uuid.'-'.$payment->refunded_amount.'-'.$amount]));

        return (string) $refund->id;
    }

    /** Mirrors a local coupon as a one-time Stripe coupon (created on first use). */
    private function stripeCouponId(Coupon $coupon): string
    {
        if ($coupon->stripe_coupon_id) {
            return $coupon->stripe_coupon_id;
        }

        $params = ['duration' => 'once', 'name' => $coupon->code, 'metadata' => ['coupon_uuid' => $coupon->uuid]];
        $params += $coupon->type === CouponType::Percent
            ? ['percent_off' => $coupon->value]
            : ['amount_off' => $coupon->value, 'currency' => strtolower((string) $coupon->currency)];

        $id = $this->call(fn (StripeClient $s) => $s->coupons->create($params)->id);
        $coupon->forceFill(['stripe_coupon_id' => $id])->save();

        return $id;
    }

    /**
     * @template T
     *
     * @param  callable(StripeClient): T  $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn(Cashier::stripe());
        } catch (ApiErrorException $e) {
            report($e);
            throw new GatewayException($e->getError()->message ?? 'The payment provider rejected the request.');
        }
    }
}
