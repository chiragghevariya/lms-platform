<?php

namespace App\Http\Resources;

use App\Models\CourseReview;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseReview */
class CourseReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'user' => ['name' => $this->user->name, 'avatar_url' => app(AvatarService::class)->url($this->user->avatar_path)],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
