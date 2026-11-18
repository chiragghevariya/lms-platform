<?php

namespace App\Billing\Gateways;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Billing\GatewayException;
use App\Billing\PaymentGateway;
use App\Enums\Gateway;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;

/**
 * Load-test stand-in for Stripe/Razorpay: no network, a configurable artificial latency that
 * mimics the provider's checkout API. Only bound when BILLING_SANDBOX_GATEWAY=true outside
 * production (see GatewayResolver).
 */
class SandboxGateway implements PaymentGateway
{
    public function __construct(private readonly Gateway $as) {}

    public function gateway(): Gateway
    {
        return $this->as;
    }

    public function checkout(User $user, PlanPrice $price, ?Coupon $coupon, CheckoutUrls $urls): CheckoutResult
    {
        usleep((int) config('billing.sandbox_latency_ms', 150) * 1000);

        return new CheckoutResult($this->as, redirectUrl: $urls->success.'?sandbox=1');
    }

    public function checkoutCourse(User $user, Course $course, CheckoutUrls $urls): CheckoutResult
    {
        usleep((int) config('billing.sandbox_latency_ms', 150) * 1000);

        return new CheckoutResult($this->as, redirectUrl: $urls->success.'?sandbox=1');
    }

    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $immediate): void {}

    public function cancelScheduledChange(Subscription $subscription): void {}

    public function cancelAtPeriodEnd(Subscription $subscription): void {}

    public function resume(Subscription $subscription): void {}

    public function cancelNow(Subscription $subscription): void {}

    public function paymentMethodUrl(User $user, string $returnUrl): ?string
    {
        return null;
    }

    public function refund(Payment $payment, int $amount, string $reason): string
    {
        throw new GatewayException('Sandbox gateway does not refund.');
    }
}
