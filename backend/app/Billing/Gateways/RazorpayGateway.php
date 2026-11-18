<?php

namespace App\Billing\Gateways;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Billing\GatewayException;
use App\Billing\PaymentGateway;
use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;

/**
 * Razorpay Subscriptions API (recurring) + Orders API (lifetime), paid in the Razorpay
 * Checkout modal. Notes:
 *  - Immediate plan updates are prorated by Razorpay; only card-authorised subscriptions
 *    can be updated (UPI / eMandate cannot).
 *  - Cancellations cannot be undone, so BillingService defers sending them (see razorpay_cancel_lead_hours).
 */
class RazorpayGateway implements PaymentGateway
{
    public function __construct(private readonly RazorpayClient $api) {}

    public function gateway(): Gateway
    {
        return Gateway::Razorpay;
    }

    public function checkout(User $user, PlanPrice $price, ?Coupon $coupon, CheckoutUrls $urls): CheckoutResult
    {
        $notes = array_filter(['user_uuid' => $user->uuid, 'plan_price_uuid' => $price->uuid, 'coupon_uuid' => $coupon?->uuid]);
        $base = [
            'key' => $this->api->keyId(),
            'name' => (string) config('lms.brand.name'),
            'description' => $price->plan->name.' membership',
            'prefill' => array_filter(['name' => $user->name, 'email' => $user->email, 'contact' => $user->phone]),
            'notes' => $notes,
        ];

        if ($price->isLifetime()) {
            $amount = $price->amount - ($coupon?->discountFor($price->amount, $price->currency) ?? 0);
            $order = $this->api->post('/orders', [
                'amount' => $amount,
                'currency' => $price->currency,
                'receipt' => substr('lt_'.$price->uuid, 0, 40),
                'notes' => $notes,
            ]);

            return new CheckoutResult(Gateway::Razorpay, razorpay: $base + [
                'order_id' => $order['id'], 'amount' => $amount, 'currency' => $price->currency,
            ]);
        }

        if (! $price->razorpay_plan_id) {
            throw new GatewayException('This plan is not available for Razorpay payments yet.', 'price_not_configured');
        }

        $trial = $price->trial_days > 0 && ! $user->subscriptions()->withTrashed()->exists();
        $remote = $this->api->post('/subscriptions', array_filter([
            'plan_id' => $price->razorpay_plan_id,
            'total_count' => (int) config('billing.razorpay.total_count.'.$price->interval->value, 12),
            'quantity' => 1,
            'customer_notify' => 1,
            'start_at' => $trial ? now()->addDays($price->trial_days)->getTimestamp() : null,
            'offer_id' => $coupon?->razorpay_offer_id,
            'notes' => $notes,
        ]));

        // Local placeholder so webhooks/verification can find the user even before activation.
        $user->subscriptions()->create([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'gateway' => Gateway::Razorpay,
            'gateway_subscription_id' => $remote['id'],
            'status' => SubscriptionStatus::Incomplete,
            'coupon_id' => $coupon?->id,
        ]);

        return new CheckoutResult(Gateway::Razorpay, razorpay: $base + ['subscription_id' => $remote['id']]);
    }

    public function checkoutCourse(User $user, Course $course, CheckoutUrls $urls): CheckoutResult
    {
        $notes = ['kind' => 'course', 'user_uuid' => $user->uuid, 'course_uuid' => $course->uuid];
        $order = $this->api->post('/orders', [
            'amount' => (int) $course->price_amount,
            'currency' => (string) $course->currency,
            'receipt' => substr('c_'.$course->uuid, 0, 40),
            'notes' => $notes,
        ]);

        return new CheckoutResult(Gateway::Razorpay, razorpay: [
            'key' => $this->api->keyId(),
            'name' => (string) config('lms.brand.name'),
            'description' => $course->title,
            'prefill' => array_filter(['name' => $user->name, 'email' => $user->email, 'contact' => $user->phone]),
            'notes' => $notes,
            'order_id' => $order['id'],
            'amount' => (int) $course->price_amount,
            'currency' => (string) $course->currency,
        ]);
    }

    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $immediate): void
    {
        if (! $newPrice->razorpay_plan_id) {
            throw new GatewayException('That plan is not available for Razorpay payments yet.', 'price_not_configured');
        }

        $this->api->patch('/subscriptions/'.$subscription->gateway_subscription_id, [
            'plan_id' => $newPrice->razorpay_plan_id,
            'schedule_change_at' => $immediate ? 'now' : 'cycle_end',
            'customer_notify' => 1,
        ]);
    }

    public function cancelScheduledChange(Subscription $subscription): void
    {
        $this->api->post('/subscriptions/'.$subscription->gateway_subscription_id.'/cancel_scheduled_changes');
    }

    /** Sends the (irreversible) cancel-at-cycle-end to Razorpay. Called near renewal by the scheduler. */
    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        $this->api->post('/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', ['cancel_at_cycle_end' => 1]);
    }

    public function resume(Subscription $subscription): void
    {
        if ($subscription->gateway_cancel_requested_at !== null) {
            throw new GatewayException('This subscription is too close to its renewal date to resume. You can subscribe again after it ends.', 'resume_unavailable', 409);
        }
        // Cancellation was only recorded locally; nothing to undo at Razorpay.
    }

    public function cancelNow(Subscription $subscription): void
    {
        $this->api->post('/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', ['cancel_at_cycle_end' => 0]);
    }

    public function paymentMethodUrl(User $user, string $returnUrl): ?string
    {
        return null; // Razorpay has no hosted card-update page for subscriptions.
    }

    public function refund(Payment $payment, int $amount, string $reason): string
    {
        $id = (string) $payment->gateway_payment_id;
        if (! str_starts_with($id, 'pay_')) {
            throw new GatewayException('This payment has no Razorpay payment id on record. Refund it in the Razorpay dashboard; the webhook will update it here.', 'refund_unsupported');
        }

        $refund = $this->api->post('/payments/'.$id.'/refund', [
            'amount' => $amount,
            'speed' => 'normal',
            'notes' => ['payment_uuid' => $payment->uuid, 'note' => mb_substr($reason, 0, 250)],
        ]);

        return (string) ($refund['id'] ?? '');
    }

    /** @return array<string, mixed> */
    public function fetchSubscription(string $id): array
    {
        return $this->api->get('/subscriptions/'.$id);
    }

    /** @return array<string, mixed> */
    public function fetchPayment(string $id): array
    {
        return $this->api->get('/payments/'.$id);
    }
}
