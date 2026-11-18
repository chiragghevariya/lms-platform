<?php

namespace App\Http\Resources;

use App\Courses\CourseWorkflow;
use App\Models\Course;
use App\Models\LessonResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full editable course for the curriculum builder.
 *
 * @mixin Course
 */
class InstructorCourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...(new CourseCardResource($this->resource))->toArray($request),
            'description' => $this->description,
            'category_id' => $this->category?->uuid,
            'tags' => $this->tags->pluck('name')->values(),
            'currency' => $this->currency,
            'price_amount' => $this->price_amount,
            'promo_video' => $this->promoVideo ? new VideoResource($this->promoVideo) : null,
            'review_notes' => $this->review_notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'readiness' => app(CourseWorkflow::class)->readinessProblems($this->resource),
            'is_owner' => $request->user()?->id === $this->instructor_id,
            'sections' => $this->curriculum(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function curriculum(): array
    {
        $sections = [];
        foreach ($this->sections as $section) {
            $lessons = [];
            foreach ($section->lessons as $l) {
                $lessons[] = [
                    'id' => $l->uuid,
                    'title' => $l->title,
                    'type' => $l->type->value,
                    'content' => $l->content,
                    'duration_seconds' => $l->duration_seconds,
                    'is_preview' => $l->is_preview,
                    'drip_days' => $l->drip_days,
                    'has_attachment' => $l->attachment_path !== null,
                    'has_quiz' => $l->type->value === 'quiz' && $l->quiz()->whereHas('questions')->exists(),
                    'has_assignment' => $l->type->value === 'assignment' && $l->assignment()->exists(),
                    'video' => $l->video ? new VideoResource($l->video) : null,
                    'resources' => $l->resources->map(fn (LessonResource $r) => ['id' => $r->uuid, 'title' => $r->title, 'size' => $r->size, 'mime' => $r->mime])->values()->all(),
                ];
            }
            $sections[] = ['id' => $section->uuid, 'title' => $section->title, 'lessons' => $lessons];
        }

        return $sections;
    }
}
