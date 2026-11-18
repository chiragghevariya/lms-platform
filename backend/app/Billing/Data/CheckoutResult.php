<?php

namespace App\Billing\Data;

use App\Enums\Gateway;

/** What the client needs to complete payment: a redirect (Stripe) or a modal payload (Razorpay). */
final class CheckoutResult
{
    /** @param  array<string, mixed>|null  $razorpay */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly ?string $redirectUrl = null,
        public readonly ?array $razorpay = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway->value,
            'action' => $this->redirectUrl ? 'redirect' : 'razorpay_modal',
            'redirect_url' => $this->redirectUrl,
            'razorpay' => $this->razorpay,
        ];
    }
}
