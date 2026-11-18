<?php

namespace App\Billing;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Enums\Gateway;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;

/**
 * Contract each payment provider implements. Gateways perform remote actions only;
 * local subscription state changes arrive through webhooks (SubscriptionSync).
 */
interface PaymentGateway
{
    public function gateway(): Gateway;

    /** Starts checkout for a recurring price or a one-time (lifetime) price. */
    public function checkout(User $user, PlanPrice $price, ?Coupon $coupon, CheckoutUrls $urls): CheckoutResult;

    /** One-time purchase of a single course (Stripe payment-mode Checkout / Razorpay order). */
    public function checkoutCourse(User $user, Course $course, CheckoutUrls $urls): CheckoutResult;

    /** Immediate change (upgrade, gateway prorates) or at period end (downgrade). */
    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $immediate): void;

    /** Withdraws a pending period-end plan change. */
    public function cancelScheduledChange(Subscription $subscription): void;

    public function cancelAtPeriodEnd(Subscription $subscription): void;

    public function resume(Subscription $subscription): void;

    public function cancelNow(Subscription $subscription): void;

    /** Hosted page where the customer can update their card; null if unsupported. */
    public function paymentMethodUrl(User $user, string $returnUrl): ?string;

    /** Refunds part or all of a captured payment; returns the gateway's refund id. */
    public function refund(Payment $payment, int $amount, string $reason): string;
}
