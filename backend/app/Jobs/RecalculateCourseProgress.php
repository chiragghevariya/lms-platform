<?php

namespace App\Jobs;

use App\Learning\GamificationService;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Recomputes a learner's course percent; on first reaching 100% marks completion and issues the certificate. */
class RecalculateCourseProgress implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 30;

    public function __construct(public int $userId, public int $courseId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->courseId;
    }

    public function handle(GamificationService $gamification): void
    {
        $user = User::find($this->userId);
        $course = Course::find($this->courseId);
        if (! $user || ! $course) {
            return;
        }

        $lessonIds = $course->lessons()->where('is_optional', false)->pluck('id'); // replays etc. don't block completion
        $total = $lessonIds->count();
        $done = LessonProgress::where('user_id', $user->id)->whereIn('lesson_id', $lessonIds)->whereNotNull('completed_at')->count();
        $percent = $total ? (int) floor($done / $total * 100) : 0;

        $cp = CourseProgress::firstOrNew(['user_id' => $user->id, 'course_id' => $course->id]);
        $justCompleted = $percent >= 100 && $cp->completed_at === null;
        $cp->forceFill([
            'percent' => $percent,
            'completed_lessons' => $done,
            'total_lessons' => $total,
            'completed_at' => $percent >= 100 ? ($cp->completed_at ?? now()) : null,
        ])->save();

        if ($justCompleted) {
            $gamification->award($user, 'course_completed', 'course:'.$course->id);
            $gamification->grantBadge($user, 'first_course');
            IssueCertificate::dispatch($user->id, $course->id);
        }
    }
}
