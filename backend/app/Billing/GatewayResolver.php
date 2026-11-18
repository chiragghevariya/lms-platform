<?php

namespace App\Billing;

use App\Billing\Gateways\RazorpayGateway;
use App\Billing\Gateways\SandboxGateway;
use App\Billing\Gateways\StripeGateway;
use App\Enums\Gateway;
use App\Models\PlanPrice;
use App\Models\User;

class GatewayResolver
{
    /** INR prices (shown to Indian users) go through Razorpay; every other currency through Stripe. */
    public function forPrice(PlanPrice $price): PaymentGateway
    {
        return $this->for(strtoupper($price->currency) === 'INR' ? Gateway::Razorpay : Gateway::Stripe);
    }

    public function for(Gateway $gateway): PaymentGateway
    {
        if (config('billing.sandbox_gateway') && ! app()->isProduction() && $gateway !== Gateway::Manual) {
            return new SandboxGateway($gateway); // load tests only
        }

        return match ($gateway) {
            Gateway::Stripe => app(StripeGateway::class),
            Gateway::Razorpay => app(RazorpayGateway::class),
            Gateway::Manual => throw new GatewayException('Complimentary memberships are managed by our team.', 'manual_subscription', 409),
        };
    }

    /** Display currency: INR for Indian users, otherwise USD (or the configured default for guests). */
    public function currencyFor(?User $user): string
    {
        if ($user?->country) {
            return in_array(strtoupper($user->country), (array) config('billing.razorpay_countries'), true) ? 'INR' : 'USD';
        }

        return (string) config('billing.default_currency');
    }
}
