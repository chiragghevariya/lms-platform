<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $graceEnds = $this->past_due_since?->copy()->addDays((int) config('billing.grace_days'));

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'gateway' => $this->gateway->value,
            'is_active' => $this->grantsAccess(),
            'is_lifetime' => $this->is_lifetime,
            'is_complimentary' => $this->isManual(),
            'tier_level' => $this->plan->tier_level,
            'tier' => $this->plan->tier()->slug(),
            'plan' => new PlanResource($this->plan),
            'price' => $this->planPrice ? new PlanPriceResource($this->planPrice) : null,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            // Razorpay cancellations become irreversible once forwarded (near renewal).
            'can_resume' => $this->cancel_at_period_end && ($this->gateway->value === 'stripe' || $this->gateway_cancel_requested_at === null),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'past_due' => $this->past_due_since !== null,
            'grace_ends_at' => $graceEnds?->toIso8601String(),
            'scheduled_change' => $this->scheduled_plan_price_id ? [
                'price' => $this->scheduledPrice ? new PlanPriceResource($this->scheduledPrice) : null,
                'plan' => $this->scheduledPrice ? new PlanResource($this->scheduledPrice->plan) : null,
                'effective_at' => $this->scheduled_change_at?->toIso8601String(),
            ] : null,
            'notes' => $this->when($request->user()?->isStaff() ?? false, $this->notes),
            'user' => $this->when(($request->user()?->isStaff() ?? false) && $this->relationLoaded('user'), fn () => ['id' => $this->user->uuid, 'name' => $this->user->name, 'email' => $this->user->email]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
