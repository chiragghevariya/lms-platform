<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catalogue card. `has_access` is set by the controller (batch-computed) when a user is known.
 *
 * @mixin Course
 */
class CourseCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tier = $this->requiredTier();

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'thumbnail_url' => $this->thumbnailUrl(),
            'thumbnail_srcset' => $this->thumbnailSrcset(),
            'level' => $this->level->value,
            'language' => $this->language,
            'required_tier' => $tier?->value,
            'tier' => $tier?->slug(),
            'instructor' => [
                'id' => $this->instructor->uuid,
                'name' => $this->instructor->name,
                'avatar_url' => app(AvatarService::class)->url($this->instructor->avatar_path),
            ],
            'category' => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null,
            'duration_minutes' => $this->duration_minutes,
            'lessons_count' => $this->lessons_count,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'is_featured' => $this->is_featured,
            'price' => $this->price()?->toArray(),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'has_access' => $this->when(isset($this->resource->has_access), fn () => (bool) $this->resource->has_access),
        ];
    }
}
