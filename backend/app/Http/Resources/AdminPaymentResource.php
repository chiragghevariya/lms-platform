<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;

/** @mixin Payment */
class AdminPaymentResource extends PaymentResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'type' => $this->course_id ? 'course' : 'membership',
            'currency' => $this->currency,
            'amount_minor' => $this->amount,
            'refunded_minor' => $this->refunded_amount,
            'refundable_minor' => in_array($this->status->value, ['succeeded'], true) ? max(0, $this->amount - $this->refunded_amount) : 0,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'disputed_at' => $this->disputed_at?->toIso8601String(),
            'gateway_payment_id' => $this->gateway_payment_id,
            'billing_country' => $this->billing_country,
            'user' => $this->relationLoaded('user') ? ['id' => $this->user->uuid, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'subscription_id' => $this->relationLoaded('subscription') ? $this->subscription?->uuid : null,
        ];
    }
}
