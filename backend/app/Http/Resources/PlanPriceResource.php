<?php

namespace App\Http\Resources;

use App\Models\PlanPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanPrice */
class PlanPriceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->uuid,
            'interval' => $this->interval->value,
            'price' => $this->price()->toArray(),
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'gateway' => strtoupper($this->currency) === 'INR' ? 'razorpay' : 'stripe',
            'stripe_price_id' => $this->when($isAdmin, $this->stripe_price_id),
            'razorpay_plan_id' => $this->when($isAdmin, $this->razorpay_plan_id),
        ];
    }
}
