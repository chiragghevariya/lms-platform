<?php

namespace App\Http\Resources;

use App\Admin\Impersonation;
use App\Models\Affiliate;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('profile');
        $profile = $this->profile;
        $tier = $this->currentTier();
        $active = $this->activeSubscription();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => app(AvatarService::class)->url($this->avatar_path),
            'country' => $this->country,
            'timezone' => $this->timezone,
            'bio' => $this->bio,
            'status' => $this->status->value,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'has_google' => $this->google_id !== null,
            'profile' => [
                'headline' => $profile?->headline,
                'website' => $profile?->website,
                'city' => $profile?->city,
                'state' => $profile?->state,
                'occupation' => $profile?->occupation,
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'social_links' => $profile->social_links ?? (object) [],
                'interests' => $profile->interests ?? [],
                'expertise' => $profile->expertise ?? [],
            ],
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->when(
                $request->user()?->is($this->resource) ?? false,
                fn () => $this->getAllPermissions()->pluck('name')->values(),
            ),
            'is_staff' => $this->isStaff(),
            // Set while an admin is "viewing as" this user (admin panel impersonation).
            'impersonator' => $this->when(
                $request->user()?->is($this->resource) ?? false,
                fn () => ($admin = Impersonation::impersonator($request)) ? ['id' => $admin->uuid, 'name' => $admin->name] : null,
            ),
            'affiliate' => (function () {
                $a = Affiliate::where('user_id', $this->id)->first(['code', 'status', 'approved_at']);

                return [
                    'active' => $a?->status === 'approved',
                    'status' => $a?->status,
                    'code' => $a?->code,
                    'activated_at' => $a?->approved_at?->toIso8601String(),
                ];
            })(),
            'membership' => [
                'tier' => $tier?->slug(),
                'tier_rank' => $tier->value ?? 0,
                'ends_at' => $active?->current_period_end?->toIso8601String(),
                'is_lifetime' => $active->is_lifetime ?? false,
                'status' => $active?->status->value,
            ],
            'billing' => $this->when($request->user()?->is($this->resource) ?? false, fn () => [
                'name' => $this->billing_name,
                'address' => $this->billing_address ?? (object) [],
                'tax_id' => $this->tax_id,
            ]),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
