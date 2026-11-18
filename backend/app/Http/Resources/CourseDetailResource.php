<?php

namespace App\Http\Resources;

use App\Courses\CourseAccessService;
use App\Models\Bookmark;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public course page + curriculum outline with per-lesson lock state for the viewer.
 *
 * @mixin Course
 */
class CourseDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user() ?? $request->user('sanctum');
        $access = app(CourseAccessService::class);
        $avatars = app(AvatarService::class);
        $enrollment = $access->enrollmentFor($user, $this->resource);
        $reason = $access->accessReason($user, $this->resource);
        $completed = $user ? LessonProgress::where('user_id', $user->id)->where('course_id', $this->id)->whereNotNull('completed_at')->pluck('lesson_id')->flip() : collect();

        $instructorCard = fn (User $u) => [
            'id' => $u->uuid,
            'name' => $u->name,
            'avatar_url' => $avatars->url($u->avatar_path),
            'headline' => $u->profile?->headline,
            'bio' => $u->bio,
        ];

        $sections = $this->sections->map(fn (CourseSection $s) => [
            'id' => $s->uuid,
            'title' => $s->title,
            'lessons' => $s->lessons->map(function (Lesson $l) use ($access, $user, $enrollment, $completed) {
                $l->setRelation('course', $this->resource);
                $state = $access->lessonState($user, $l, $enrollment);

                return [
                    'id' => $l->uuid,
                    'title' => $l->title,
                    'type' => $l->type->value,
                    'duration_seconds' => $l->duration_seconds,
                    'is_preview' => $l->is_preview,
                    'drip_days' => $l->drip_days,
                    'unlocked' => $state['unlocked'],
                    'lock_reason' => $state['unlocked'] ? null : $state['reason'],
                    'available_at' => $state['available_at']?->toIso8601String(),
                    'completed' => $completed->has($l->id),
                ];
            })->values(),
        ])->values();

        $nextLesson = $sections->flatMap(fn ($s) => $s['lessons'])->first(fn ($l) => $l['unlocked'] && ! $l['completed']);
        $courseProgress = $user ? CourseProgress::where('user_id', $user->id)->where('course_id', $this->id)->first() : null;
        $tier = $this->requiredTier();

        return [
            ...(new CourseCardResource($this->resource))->toArray($request),
            'description' => $this->description,
            'tags' => $this->tags->pluck('name')->values(),
            'has_promo' => $this->promoVideo?->isReady() ?? false,
            'instructors' => collect([$this->instructor, ...$this->coInstructors->all()])->unique('id')->map(fn (User $u) => $instructorCard($u))->values()->all(),
            'curriculum' => $sections,
            'access' => [
                'has_access' => $reason !== null,
                'reason' => $reason,
                'required_tier' => $tier?->value,
                'purchased' => $reason === 'purchase',
                'can_purchase' => $reason === null && $this->price_amount !== null && $this->isPublished(),
                'enrolled' => $enrollment !== null,
                'progress_percent' => $courseProgress->percent ?? 0,
                'completed_at' => $courseProgress?->completed_at?->toIso8601String(),
                'certificate_id' => $user ? Certificate::where('user_id', $user->id)->where('course_id', $this->id)->value('uuid') : null,
                'bookmarked' => $user ? Bookmark::where('user_id', $user->id)->where('course_id', $this->id)->whereNull('lesson_id')->exists() : false,
                'next_lesson_id' => $nextLesson['id'] ?? null,
            ],
            'my_review' => $user ? $this->reviews->firstWhere('user_id', $user->id)?->only(['rating', 'comment']) : null,
        ];
    }
}
