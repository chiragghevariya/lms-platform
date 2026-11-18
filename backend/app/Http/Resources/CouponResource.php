<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type->value,
            'value' => $this->value,
            'currency' => $this->currency,
            'max_uses' => $this->max_uses,
            'times_used' => $this->times_used,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'razorpay_offer_id' => $this->razorpay_offer_id,
            'plan_ids' => $this->whenLoaded('plans', fn () => $this->plans->pluck('uuid')->values()),
        ];
    }
}
