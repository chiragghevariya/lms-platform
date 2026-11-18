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

/**
 * Maps Stripe events/objects (as arrays) onto local state. Handles both the classic
 * API shape and the 2025+ shape (periods on subscription items, invoice.parent).
 */
class StripeEventHandler
{
    public function __construct(private readonly SubscriptionSync $sync) {}

    /** @param array<string, mixed> $event  Returns false when the event type is not relevant. */
    public function handle(array $event): bool
    {
        /** @var array<string, mixed> $object */
        $object = Arr::get($event, 'data.object', []);

        match ($event['type'] ?? '') {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->syncCheckoutSession($object),
            'customer.subscription.created', 'customer.subscription.updated',
            'customer.subscription.deleted', 'customer.subscription.trial_will_end' => $this->syncSubscription($object),
            'invoice.paid', 'invoice.payment_succeeded' => $this->syncInvoice($object, PaymentStatus::Succeeded),
            'invoice.payment_failed' => $this->syncInvoice($object, PaymentStatus::Failed),
            'charge.succeeded' => $this->refunds()->fingerprint(Gateway::Stripe, $this->chargeRefs($object), $this->stringOrNull(Arr::get($object, 'payment_method_details.card.fingerprint'))),
            'charge.refunded' => $this->refunds()->refund(Gateway::Stripe, $this->chargeRefs($object), (int) ($object['amount_refunded'] ?? 0)),
            'charge.dispute.created' => $this->refunds()->dispute(Gateway::Stripe, array_filter([
                $this->stringOrNull($object['payment_intent'] ?? null), $this->stringOrNull($object['charge'] ?? null),
            ])),
            default => null,
        };

        return in_array($event['type'] ?? '', [
            'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'customer.subscription.created',
            'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.trial_will_end',
            'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed',
            'charge.succeeded', 'charge.refunded', 'charge.dispute.created',
        ], true);
    }

    /** @param array<string, mixed> $session */
    public function syncCheckoutSession(array $session): ?Subscription
    {
        $user = $this->userFrom(Arr::get($session, 'metadata', []), Arr::get($session, 'customer'), Arr::get($session, 'client_reference_id'));
        if (! $user) {
            return null;
        }

        if (($session['mode'] ?? null) === 'subscription') {
            $sub = Arr::get($session, 'subscription');

            return is_array($sub) ? $this->syncSubscription($sub) : null; // otherwise customer.subscription.* will follow
        }

        if (($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        // One-time payment: single course purchase …
        $courseUuid = Arr::get($session, 'metadata.course_uuid');
        if (is_string($courseUuid) && ($course = Course::withTrashed()->where('uuid', $courseUuid)->first())) {
            app(CoursePurchaseService::class)->complete(
                Gateway::Stripe,
                (string) (Arr::get($session, 'payment_intent') ?: $session['id']),
                $user,
                $course,
                (int) ($session['amount_total'] ?? $course->price_amount),
                (string) ($session['currency'] ?? $course->currency),
            );

            return null;
        }

        // … or a lifetime plan.

        $price = $this->priceFrom(Arr::get($session, 'metadata', []), null);
        if (! $price) {
            return null;
        }
        $coupon = $this->couponFrom(Arr::get($session, 'metadata', []));
        $reference = (string) (Arr::get($session, 'payment_intent') ?: $session['id']);

        $subscription = $this->sync->upsert(Gateway::Stripe, $reference, [
            'user' => $user, 'price' => $price, 'status' => SubscriptionStatus::Active,
            'period_start' => now(), 'is_lifetime' => true, 'coupon' => $coupon,
            'customer_id' => is_string($session['customer'] ?? null) ? $session['customer'] : null,
        ]);

        $this->sync->recordPayment(Gateway::Stripe, $reference, [
            'user' => $user, 'subscription' => $subscription, 'price' => $price, 'coupon' => $coupon,
            'amount' => (int) ($session['amount_total'] ?? $price->amount),
            'discount' => (int) Arr::get($session, 'total_details.amount_discount', 0),
            'currency' => (string) ($session['currency'] ?? $price->currency),
            'status' => PaymentStatus::Succeeded,
        ]);

        return $subscription;
    }

    /** @param array<string, mixed> $sub */
    public function syncSubscription(array $sub): ?Subscription
    {
        $item = Arr::get($sub, 'items.data.0', []);
        $price = $this->priceFrom(Arr::get($sub, 'metadata', []), Arr::get($item, 'price.id'));
        $user = $this->userFrom(Arr::get($sub, 'metadata', []), Arr::get($sub, 'customer'), null);

        return $this->sync->upsert(Gateway::Stripe, (string) $sub['id'], [
            'user' => $user,
            'price' => $price,
            'status' => $this->mapStatus((string) ($sub['status'] ?? 'incomplete')),
            'period_start' => $this->ts($item['current_period_start'] ?? $sub['current_period_start'] ?? null),
            'period_end' => $this->ts($item['current_period_end'] ?? $sub['current_period_end'] ?? null),
            'trial_ends_at' => $this->ts($sub['trial_end'] ?? null),
            'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
            'cancelled_at' => $this->ts($sub['canceled_at'] ?? null),
            'ended_at' => $this->ts($sub['ended_at'] ?? null),
            'customer_id' => is_string($sub['customer'] ?? null) ? $sub['customer'] : null,
            'coupon' => $this->couponFrom(Arr::get($sub, 'metadata', [])),
        ]);
    }

    /** @param array<string, mixed> $invoice */
    public function syncInvoice(array $invoice, PaymentStatus $status): void
    {
        $subId = Arr::get($invoice, 'parent.subscription_details.subscription') ?? Arr::get($invoice, 'subscription');
        if (! is_string($subId)) {
            return; // not a subscription invoice (lifetime checkouts are handled via the session)
        }

        $subscription = Subscription::where('gateway', Gateway::Stripe)->where('gateway_subscription_id', $subId)->first();
        if (! $subscription) {
            return; // subscription.created not processed yet; Stripe retries the invoice event
        }

        $line = Arr::get($invoice, 'lines.data.0', []);
        $price = PlanPrice::where('stripe_price_id', Arr::get($line, 'pricing.price_details.price') ?? Arr::get($line, 'price.id'))->first();
        $discount = array_sum(array_column((array) Arr::get($invoice, 'total_discount_amounts', []), 'amount'));

        $this->sync->recordPayment(Gateway::Stripe, (string) $invoice['id'], [
            'user' => $subscription->user,
            'subscription' => $subscription,
            'price' => $price ?? $subscription->planPrice,
            'gateway_invoice_id' => (string) $invoice['id'],
            // Refund/dispute events reference the PaymentIntent, not the invoice (2025+ API keeps it under payments).
            'gateway_reference' => $this->stringOrNull($invoice['payment_intent'] ?? Arr::get($invoice, 'payments.data.0.payment.payment_intent')),
            'amount' => (int) ($status === PaymentStatus::Succeeded ? ($invoice['amount_paid'] ?? 0) : ($invoice['amount_due'] ?? 0)),
            'discount' => (int) $discount,
            'currency' => (string) ($invoice['currency'] ?? $subscription->planPrice->currency ?? 'usd'),
            'status' => $status,
            'period_start' => $this->ts(Arr::get($line, 'period.start')),
            'period_end' => $this->ts(Arr::get($line, 'period.end')),
            'failure_reason' => $status === PaymentStatus::Failed ? 'Card payment failed' : null,
        ]);

        // Keep status in step even if the subscription.updated event is delayed.
        if ($status === PaymentStatus::Failed && $subscription->status !== SubscriptionStatus::PastDue) {
            $this->sync->upsert(Gateway::Stripe, $subId, ['status' => SubscriptionStatus::PastDue]);
        } elseif ($status === PaymentStatus::Succeeded && $subscription->status === SubscriptionStatus::PastDue) {
            $this->sync->upsert(Gateway::Stripe, $subId, [
                'status' => SubscriptionStatus::Active,
                'period_start' => $this->ts(Arr::get($line, 'period.start')),
                'period_end' => $this->ts(Arr::get($line, 'period.end')),
            ]);
        }
    }

    private function mapStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled' => SubscriptionStatus::Cancelled,
            'incomplete_expired', 'paused' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Incomplete,
        };
    }

    /** @param array<string, mixed>|mixed $metadata */
    private function userFrom(mixed $metadata, mixed $customer, mixed $reference): ?User
    {
        $uuid = (is_array($metadata) ? ($metadata['user_uuid'] ?? null) : null) ?? $reference;
        $user = is_string($uuid) ? User::where('uuid', $uuid)->first() : null;
        $user ??= is_string($customer) ? User::where('stripe_id', $customer)->first() : null;

        if ($user && is_string($customer) && ! $user->stripe_id) {
            $user->forceFill(['stripe_id' => $customer])->save();
        }

        return $user;
    }

    /** Price id on the subscription item wins (reflects plan changes); metadata is the fallback. @param array<string, mixed>|mixed $metadata */
    private function priceFrom(mixed $metadata, mixed $stripePriceId): ?PlanPrice
    {
        if (is_string($stripePriceId) && ($price = PlanPrice::withTrashed()->where('stripe_price_id', $stripePriceId)->first())) {
            return $price;
        }
        $uuid = is_array($metadata) ? ($metadata['plan_price_uuid'] ?? null) : null;

        return is_string($uuid) ? PlanPrice::withTrashed()->where('uuid', $uuid)->first() : null;
    }

    /** @param array<string, mixed>|mixed $metadata */
    private function couponFrom(mixed $metadata): ?Coupon
    {
        $uuid = is_array($metadata) ? ($metadata['coupon_uuid'] ?? null) : null;

        return is_string($uuid) ? Coupon::withTrashed()->where('uuid', $uuid)->first() : null;
    }

    private function ts(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    /**
     * Our payment may be keyed by the invoice id (subscriptions) or the PaymentIntent (one-time purchases).
     *
     * @param  array<string, mixed>  $charge
     * @return list<string>
     */
    private function chargeRefs(array $charge): array
    {
        return array_values(array_filter([
            $this->stringOrNull($charge['invoice'] ?? null), $this->stringOrNull($charge['payment_intent'] ?? null), $this->stringOrNull($charge['id'] ?? null),
        ]));
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function refunds(): RefundRecorder
    {
        return app(RefundRecorder::class);
    }
}
