<?php

namespace App\Billing;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionEnded;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies gateway state to local subscriptions/payments. Every method is idempotent so
 * webhook retries, duplicates and the redirect-time "sync now" fallback are all safe.
 */
class SubscriptionSync
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly InvoiceService $invoices,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param array{
     *   user?: User|null, price?: PlanPrice|null, status: SubscriptionStatus,
     *   period_start?: Carbon|null, period_end?: Carbon|null, trial_ends_at?: Carbon|null,
     *   cancel_at_period_end?: bool|null, cancelled_at?: Carbon|null, ended_at?: Carbon|null,
     *   customer_id?: string|null, coupon?: Coupon|null, is_lifetime?: bool
     * } $state
     */
    public function upsert(Gateway $gateway, string $gatewaySubscriptionId, array $state): ?Subscription
    {
        return DB::transaction(function () use ($gateway, $gatewaySubscriptionId, $state) {
            $sub = Subscription::where('gateway', $gateway)->where('gateway_subscription_id', $gatewaySubscriptionId)
                ->lockForUpdate()->first();

            $price = $state['price'] ?? null;

            if (! $sub) {
                $user = $state['user'] ?? null;
                if (! $user || ! $price) {
                    return null; // not ours / not enough info yet (a later event will carry it)
                }
                $sub = new Subscription([
                    'user_id' => $user->id,
                    'gateway' => $gateway,
                    'gateway_subscription_id' => $gatewaySubscriptionId,
                    'plan_id' => $price->plan_id,
                    'plan_price_id' => $price->id,
                    'status' => SubscriptionStatus::Incomplete,
                    'is_lifetime' => $state['is_lifetime'] ?? $price->isLifetime(),
                    'coupon_id' => ($state['coupon'] ?? null)?->id,
                ]);
            }

            // Transitions are judged on status, not on the clock: a past_due subscription whose grace
            // already lapsed is still a live → ended transition that must notify the member.
            $previousStatus = $sub->exists ? $sub->status : null;
            $wasLive = $previousStatus?->isLive() ?? false;

            if ($price && $price->id !== $sub->plan_price_id) {
                $this->applyPriceChange($sub, $price);
            }

            $status = $state['status'];
            $sub->status = $status;

            foreach (['period_start' => 'current_period_start', 'period_end' => 'current_period_end', 'trial_ends_at' => 'trial_ends_at', 'cancelled_at' => 'cancelled_at', 'ended_at' => 'ended_at', 'customer_id' => 'gateway_customer_id'] as $in => $col) {
                if (array_key_exists($in, $state) && $state[$in] !== null) {
                    $sub->{$col} = $state[$in];
                }
            }

            // null = gateway doesn't know (e.g. Razorpay before we forward a deferred cancel): keep local value.
            if (isset($state['cancel_at_period_end'])) {
                $sub->cancel_at_period_end = $state['cancel_at_period_end'] || ($sub->cancel_at_period_end && $gateway === Gateway::Razorpay && $sub->gateway_cancel_requested_at === null);
            }

            if ($status === SubscriptionStatus::PastDue) {
                $sub->past_due_since ??= now();
            } elseif (in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                $sub->past_due_since = null;
            } elseif (in_array($status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true)) {
                $sub->ended_at ??= now();
                $sub->scheduled_plan_price_id = null;
                $sub->scheduled_change_at = null;
            }

            $sub->save();

            $isLive = $status->isLive();
            DB::afterCommit(function () use ($sub, $wasLive, $isLive, $previousStatus, $status) {
                if (! $wasLive && $isLive) {
                    event(new SubscriptionActivated($sub));
                }
                if ($status === SubscriptionStatus::PastDue && $previousStatus !== SubscriptionStatus::PastDue) {
                    event(new PaymentFailed($sub));
                }
                if ($wasLive && ! $isLive) {
                    event(new SubscriptionEnded($sub));
                }
            });

            return $sub;
        });
    }

    /**
     * Downgrades scheduled for period end keep the current (higher) plan until the
     * scheduled time even if the gateway already reports the new price.
     */
    private function applyPriceChange(Subscription $sub, PlanPrice $price): void
    {
        $isScheduled = $sub->scheduled_plan_price_id === $price->id;

        if ($isScheduled && $sub->scheduled_change_at && $sub->scheduled_change_at->isFuture()) {
            return;
        }

        $sub->plan_id = $price->plan_id;
        $sub->plan_price_id = $price->id;
        $sub->is_lifetime = $sub->is_lifetime || $price->isLifetime();
        $sub->scheduled_plan_price_id = null;
        $sub->scheduled_change_at = null;
    }

    /**
     * @param array{
     *   user: User, subscription?: Subscription|null, price?: PlanPrice|null, coupon?: Coupon|null, course?: Course|null,
     *   gateway_invoice_id?: string|null, amount: int, discount?: int, currency: string,
     *   status: PaymentStatus, period_start?: Carbon|null, period_end?: Carbon|null,
     *   failure_reason?: string|null, description?: string|null, gateway_reference?: string|null, payment_fingerprint?: string|null
     * } $data
     */
    public function recordPayment(Gateway $gateway, string $gatewayPaymentId, array $data): Payment
    {
        $payment = DB::transaction(function () use ($gateway, $gatewayPaymentId, $data) {
            $payment = Payment::where('gateway', $gateway)->where('gateway_payment_id', $gatewayPaymentId)->lockForUpdate()->first();
            $user = $data['user'];

            if ($payment && $payment->status === PaymentStatus::Succeeded) {
                return $payment; // already final
            }

            $payment ??= new Payment(['gateway' => $gateway, 'gateway_payment_id' => $gatewayPaymentId, 'user_id' => $user->id]);
            $price = $data['price'] ?? $data['subscription']->planPrice ?? null;
            $tax = $this->tax->breakdown($data['amount'], $user->country);

            $payment->fill([
                'subscription_id' => ($data['subscription'] ?? null)->id ?? $payment->subscription_id,
                'plan_price_id' => $price->id ?? $payment->plan_price_id,
                'course_id' => ($data['course'] ?? null)->id ?? $payment->course_id,
                'coupon_id' => ($data['coupon'] ?? null)->id ?? $payment->coupon_id,
                'gateway_invoice_id' => $data['gateway_invoice_id'] ?? $payment->gateway_invoice_id,
                'gateway_reference' => $data['gateway_reference'] ?? $payment->gateway_reference,
                'payment_fingerprint' => $data['payment_fingerprint'] ?? $payment->payment_fingerprint,
                'status' => $data['status'],
                'currency' => strtoupper($data['currency']),
                'amount' => $data['amount'],
                'discount_amount' => $data['discount'] ?? 0,
                'subtotal' => $tax['subtotal'],
                'tax_amount' => $tax['tax_amount'],
                'tax_rate' => (string) $tax['tax_rate'],
                'tax_name' => $tax['tax_name'],
                'description' => $data['description'] ?? ($price ? $price->plan->name.' membership ('.$price->interval->value.')' : 'Membership'),
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'failure_reason' => $data['failure_reason'] ?? null,
                'billing_name' => $user->billing_name ?: $user->name,
                'billing_email' => $user->email,
                'billing_address' => $user->billing_address,
                'billing_tax_id' => $user->tax_id,
                'billing_country' => $user->country,
            ]);

            if ($data['status'] === PaymentStatus::Succeeded) {
                $payment->paid_at = now();
                $payment->invoice_number ??= $this->invoices->nextNumber();
            }

            $payment->save();

            return $payment;
        });

        if ($payment->wasChanged('status') || $payment->wasRecentlyCreated) {
            if ($payment->status === PaymentStatus::Succeeded) {
                $coupon = $data['coupon'] ?? null;
                if ($coupon) {
                    $this->coupons->redeem($coupon, $data['user'], $data['discount'] ?? 0, $payment->id, $payment->subscription_id);
                }
                event(new PaymentSucceeded($payment));
            }
        }

        return $payment;
    }

    /** Dunning: past_due beyond the grace period loses access. Returns how many were expired. */
    public function expireAfterGrace(): int
    {
        $cutoff = now()->subDays((int) config('billing.grace_days'));
        $count = 0;

        Subscription::where('status', SubscriptionStatus::PastDue)->where('past_due_since', '<=', $cutoff)
            ->each(function (Subscription $sub) use (&$count) {
                if ($sub->gateway_subscription_id) {
                    $this->upsert($sub->gateway, $sub->gateway_subscription_id, ['status' => SubscriptionStatus::Expired]);
                } else {
                    $sub->forceFill(['status' => SubscriptionStatus::Expired, 'ended_at' => now()])->save();
                    event(new SubscriptionEnded($sub));
                }
                $count++;
            });

        return $count;
    }

    /** Safety net when a period-end webhook never arrives: ends lapsed subscriptions, applies due downgrades. */
    public function reconcileLapsed(): int
    {
        $count = 0;

        // Complimentary grants and cancelled-at-period-end subscriptions whose time is up.
        Subscription::whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
            ->where('is_lifetime', false)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now()->subHours(6))
            ->where(fn ($q) => $q->where('gateway', Gateway::Manual)->orWhere('cancel_at_period_end', true))
            ->each(function (Subscription $sub) use (&$count) {
                $status = $sub->isManual() ? SubscriptionStatus::Expired : SubscriptionStatus::Cancelled;
                $sub->forceFill(['status' => $status, 'ended_at' => $sub->current_period_end])->save();
                event(new SubscriptionEnded($sub));
                $count++;
            });

        Subscription::whereNotNull('scheduled_plan_price_id')->where('scheduled_change_at', '<=', now())
            ->with('scheduledPrice')
            ->each(function (Subscription $sub) use (&$count) {
                if ($sub->scheduledPrice) {
                    $sub->forceFill([
                        'plan_id' => $sub->scheduledPrice->plan_id,
                        'plan_price_id' => $sub->scheduledPrice->id,
                        'scheduled_plan_price_id' => null,
                        'scheduled_change_at' => null,
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }
}
