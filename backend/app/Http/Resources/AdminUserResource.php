<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact user row for admin tables (no per-row queries: needs roles + liveSubscriptions.plan).
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Subscription|null $sub */
        $sub = $this->activeSubscription();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'avatar_url' => $this->avatar_path ? app(AvatarService::class)->url($this->avatar_path) : null,
            'roles' => $this->getRoleNames()->values(),
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'email_verified' => $this->email_verified_at !== null,
            'membership' => $sub ? [
                'tier' => $sub->plan->tier_level,
                'plan' => $sub->plan->name,
                'status' => $sub->status->value,
                'complimentary' => $sub->isManual(),
                'ends_at' => $sub->current_period_end?->toIso8601String(),
            ] : null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
