<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public instructor card — no email/phone.
 *
 * @mixin User
 */
class InstructorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->profile;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'avatar_url' => app(AvatarService::class)->url($this->avatar_path),
            'headline' => $profile?->headline,
            'bio' => $this->bio,
            'expertise' => $profile->expertise ?? [],
            'social_links' => $profile->social_links ?? (object) [],
        ];
    }
}
