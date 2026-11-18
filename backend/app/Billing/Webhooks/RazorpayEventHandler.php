<?php

namespace App\Billing\Webhooks;

use App\Billing\RefundRecorder;
use App\Billing\SubscriptionSync;
use App\Courses\CoursePurchaseService;
use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/** Maps Razorpay webhook payloads / API entities onto local state. */
class RazorpayEventHandler
{
    public function __construct(private readonly SubscriptionSync $sync) {}

    /** @param array<string, mixed> $event  Returns false when the event type is not relevant. */
    public function handle(array $event): bool
    {
        $type = (string) ($event['event'] ?? '');
        /** @var array<string, mixed> $sub */
        $sub = Arr::get($event, 'payload.subscription.entity', []);
        /** @var array<string, mixed> $payment */
        $payment = Arr::get($event, 'payload.payment.entity', []);

        if (str_starts_with($type, 'subscription.') && $sub) {
            $local = $this->syncSubscription($sub);

            if ($local && $payment) {
                $status = $type === 'subscription.charged' ? PaymentStatus::Succeeded : null;
                if ($status || ($payment['status'] ?? null) === 'failed') {
                    $this->recordSubscriptionPayment($local, $sub, $payment, $status ?? PaymentStatus::Failed);
                }
            }

            return true;
        }

        if ($type === 'refund.processed' || $type === 'payment.refunded') {
            /** @var array<string, mixed> $refund */
            $refund = Arr::get($event, 'payload.refund.entity', []);
            $paymentId = (string) ($refund['payment_id'] ?? $payment['id'] ?? '');
            // payment.amount_refunded is cumulative; fall back to adding this refund to what we know.
            $total = isset($payment['amount_refunded']) ? (int) $payment['amount_refunded'] : null;
            app(RefundRecorder::class)->refund(Gateway::Razorpay, [$paymentId], $total, (int) ($refund['amount'] ?? 0));

            return true;
        }

        if ($type === 'payment.dispute.created') {
            $paymentId = (string) Arr::get($event, 'payload.dispute.entity.payment_id', $payment['id'] ?? '');
            app(RefundRecorder::class)->dispute(Gateway::Razorpay, [$paymentId]);

            return true;
        }

        if ($type === 'order.paid') {
            /** @var array<string, mixed> $order */
            $order = Arr::get($event, 'payload.order.entity', []);
            $this->syncLifetimeOrder($order, $payment);

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $sub */
    public function syncSubscription(array $sub): ?Subscription
    {
        $notes = $this->notes($sub);
        $local = Subscription::where('gateway', Gateway::Razorpay)->where('gateway_subscription_id', $sub['id'])->first();
        $user = $local->user ?? (isset($notes['user_uuid']) ? User::where('uuid', $notes['user_uuid'])->first() : null);
        $price = PlanPrice::withTrashed()->where('razorpay_plan_id', $sub['plan_id'] ?? '')->first()
            ?? (isset($notes['plan_price_uuid']) ? PlanPrice::withTrashed()->where('uuid', $notes['plan_price_uuid'])->first() : null);

        $status = $this->mapStatus($sub);
        $startAt = $this->ts($sub['start_at'] ?? null);
        $inTrial = $status === SubscriptionStatus::Trialing;

        return $this->sync->upsert(Gateway::Razorpay, (string) $sub['id'], [
            'user' => $user,
            'price' => $price,
            'status' => $status,
            'period_start' => $inTrial ? ($local->current_period_start ?? now()) : $this->ts($sub['current_start'] ?? null),
            'period_end' => $inTrial ? $startAt : $this->ts($sub['current_end'] ?? null),
            'trial_ends_at' => $inTrial ? $startAt : null,
            // Razorpay can't tell us about a deferred (local-only) cancellation: null keeps the local flag.
            'cancel_at_period_end' => null,
            'cancelled_at' => ($sub['status'] ?? '') === 'cancelled' ? ($this->ts($sub['ended_at'] ?? null) ?? now()) : null,
            'ended_at' => $this->ts($sub['ended_at'] ?? null),
            'customer_id' => is_string($sub['customer_id'] ?? null) ? $sub['customer_id'] : null,
            'coupon' => isset($notes['coupon_uuid']) ? Coupon::withTrashed()->where('uuid', $notes['coupon_uuid'])->first() : null,
        ]);
    }

    /**
     * Lifetime purchase via Orders API.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payment
     */
    public function syncLifetimeOrder(array $order, array $payment): ?Subscription
    {
        $notes = $this->notes($order);
        $user = isset($notes['user_uuid']) ? User::where('uuid', $notes['user_uuid'])->first() : null;

        // Single course purchase.
        if (isset($notes['course_uuid']) && $user && ($order['status'] ?? '') === 'paid'
            && ($course = Course::withTrashed()->where('uuid', $notes['course_uuid'])->first())) {
            app(CoursePurchaseService::class)->complete(
                Gateway::Razorpay,
                (string) ($payment['id'] ?? $order['id']),
                $user,
                $course,
                (int) ($order['amount_paid'] ?? $order['amount'] ?? 0),
                (string) ($order['currency'] ?? $course->currency),
            );

            return null;
        }
        $price = isset($notes['plan_price_uuid']) ? PlanPrice::withTrashed()->where('uuid', $notes['plan_price_uuid'])->first() : null;

        if (! $user || ! $price || ($order['status'] ?? '') !== 'paid') {
            return null;
        }

        $coupon = isset($notes['coupon_uuid']) ? Coupon::withTrashed()->where('uuid', $notes['coupon_uuid'])->first() : null;
        $subscription = $this->sync->upsert(Gateway::Razorpay, (string) $order['id'], [
            'user' => $user, 'price' => $price, 'status' => SubscriptionStatus::Active,
            'period_start' => now(), 'is_lifetime' => true, 'coupon' => $coupon,
        ]);

        $paid = (int) ($order['amount_paid'] ?? $order['amount'] ?? 0);
        $this->sync->recordPayment(Gateway::Razorpay, (string) ($payment['id'] ?? $order['id']), [
            'user' => $user, 'subscription' => $subscription, 'price' => $price, 'coupon' => $coupon,
            'amount' => $paid, 'discount' => max(0, $price->amount - $paid),
            'currency' => (string) ($order['currency'] ?? $price->currency), 'status' => PaymentStatus::Succeeded,
        ]);

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $sub
     * @param  array<string, mixed>  $payment
     */
    private function recordSubscriptionPayment(Subscription $local, array $sub, array $payment, PaymentStatus $status): void
    {
        $this->sync->recordPayment(Gateway::Razorpay, (string) $payment['id'], [
            'user' => $local->user,
            'subscription' => $local,
            'price' => $local->planPrice,
            'gateway_invoice_id' => is_string($payment['invoice_id'] ?? null) ? $payment['invoice_id'] : null,
            // Card token / UPI handle — compared with the affiliate's own payments (fraud checks).
            'payment_fingerprint' => isset($payment['card_id']) ? 'card:'.$payment['card_id'] : (isset($payment['vpa']) ? 'vpa:'.strtolower((string) $payment['vpa']) : null),
            'amount' => (int) ($payment['amount'] ?? 0),
            'currency' => (string) ($payment['currency'] ?? 'INR'),
            'status' => $status,
            'period_start' => $this->ts($sub['current_start'] ?? null),
            'period_end' => $this->ts($sub['current_end'] ?? null),
            'failure_reason' => $status === PaymentStatus::Failed ? (string) ($payment['error_description'] ?? 'Payment failed') : null,
        ]);
    }

    /** @param array<string, mixed> $sub */
    private function mapStatus(array $sub): SubscriptionStatus
    {
        return match ($sub['status'] ?? '') {
            // Mandate authorised; first charge at start_at (trial) — or imminently when there's no trial.
            'authenticated' => isset($sub['start_at']) && (int) $sub['start_at'] > now()->getTimestamp() + 3600
                ? SubscriptionStatus::Trialing : SubscriptionStatus::Incomplete,
            'active' => SubscriptionStatus::Active,
            // pending = charge failed, Razorpay retrying; halted = retries exhausted. Both: dunning/grace.
            'pending', 'halted' => SubscriptionStatus::PastDue,
            'cancelled' => SubscriptionStatus::Cancelled,
            'completed', 'expired', 'paused' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Incomplete,
        };
    }

    /**
     * Razorpay sends `notes` as [] when empty.
     *
     * @param  array<string, mixed>  $entity
     * @return array<string, string>
     */
    private function notes(array $entity): array
    {
        $notes = $entity['notes'] ?? [];

        return is_array($notes) ? array_filter($notes, 'is_string') : [];
    }

    private function ts(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
