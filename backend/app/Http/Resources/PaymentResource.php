<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'gateway' => $this->gateway->value,
            'description' => $this->description,
            'amount' => $this->money($this->amount)->toArray(),
            'subtotal' => $this->money($this->subtotal)->toArray(),
            'discount' => $this->money($this->discount_amount)->toArray(),
            'tax' => [
                'name' => $this->tax_name,
                'rate' => (float) $this->tax_rate,
                'amount' => $this->money($this->tax_amount)->toArray(),
            ],
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'has_pdf' => $this->invoice_number !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
