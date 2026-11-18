<?php

namespace App\Learning;

use App\Enums\LessonType;
use App\Instructors\WatchTimeRecorder;
use App\Jobs\RecalculateCourseProgress;
use App\Models\CourseProgress;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side progress — the same on every device. Watch time only accrues through
 * heartbeats, and each heartbeat is capped by the real time elapsed since the previous one,
 * so seeking or forged requests can't fake "90% watched".
 */
class ProgressService
{
    public const HEARTBEAT_SECONDS = 15;

    public const COMPLETE_RATIO = 0.9;

    private const MAX_CREDIT_PER_BEAT = 30; // tolerates a delayed beat, never more

    public function __construct(private readonly GamificationService $gamification) {}

    /**
     * @return array{position_seconds: int, watched_seconds: int, completed: bool}
     */
    public function heartbeat(User $user, Lesson $lesson, int $position, int $claimedSeconds, float $playbackRate = 1.0): array
    {
        $progress = DB::transaction(function () use ($user, $lesson, $position, $claimedSeconds, $playbackRate) {
            $progress = LessonProgress::where('user_id', $user->id)->where('lesson_id', $lesson->id)->lockForUpdate()->first()
                ?? new LessonProgress(['user_id' => $user->id, 'lesson_id' => $lesson->id, 'course_id' => $lesson->course_id]);

            $elapsed = $progress->last_heartbeat_at
                ? (int) $progress->last_heartbeat_at->diffInSeconds(now(), true)
                : self::HEARTBEAT_SECONDS;
            // At 2x speed, 15 wall seconds cover 30 video seconds.
            $rate = max(0.25, min(2.0, $playbackRate));
            $credit = (int) min(max(0, $claimedSeconds), (int) ceil($elapsed * $rate) + 2, self::MAX_CREDIT_PER_BEAT * $rate);

            $cap = $lesson->duration_seconds > 0 ? $lesson->duration_seconds : PHP_INT_MAX;
            $progress->watched_seconds = (int) min($cap, $progress->watched_seconds + $credit);
            $progress->position_seconds = max(0, min($position, $lesson->duration_seconds ?: $position));
            $progress->last_heartbeat_at = now();
            $progress->save();

            $this->touchCourse($user, $lesson);

            return [$progress, $credit];
        });

        [$progress, $credit] = $progress;
        $this->gamification->recordActivity($user, $credit);
        app(WatchTimeRecorder::class)->record($user, $lesson->loadMissing('course'), $credit);

        if ($progress->completed_at === null && $lesson->duration_seconds > 0
            && $progress->watched_seconds >= $lesson->duration_seconds * self::COMPLETE_RATIO) {
            $this->complete($user, $lesson);
        }

        $progress->refresh();

        return ['position_seconds' => $progress->position_seconds, 'watched_seconds' => $progress->watched_seconds, 'completed' => $progress->completed_at !== null];
    }

    /** Saves resume position only (seek/pause) — never counts as watch time. */
    public function savePosition(User $user, Lesson $lesson, int $position): LessonProgress
    {
        $progress = LessonProgress::firstOrNew(['user_id' => $user->id, 'lesson_id' => $lesson->id], ['course_id' => $lesson->course_id]);
        $progress->position_seconds = max(0, $position);
        $progress->save();
        $this->touchCourse($user, $lesson);

        return $progress;
    }

    /** Learner-driven completion: only for lesson types without an objective completion rule. */
    public function markManually(User $user, Lesson $lesson, bool $completed): void
    {
        if (in_array($lesson->type, [LessonType::Video, LessonType::Quiz, LessonType::Assignment], true)) {
            throw ValidationException::withMessages(['completed' => match ($lesson->type) {
                LessonType::Video => 'Video lessons complete automatically once you have watched 90%.',
                LessonType::Quiz => 'Pass the quiz to complete this lesson.',
                default => 'This lesson completes when your assignment is graded.',
            }]);
        }

        if ($completed) {
            $this->complete($user, $lesson);

            return;
        }

        LessonProgress::where('user_id', $user->id)->where('lesson_id', $lesson->id)->update(['completed_at' => null]);
        RecalculateCourseProgress::dispatch($user->id, $lesson->course_id);
    }

    /** Idempotent. Awards points and queues the course recalculation (→ certificate). */
    public function complete(User $user, Lesson $lesson): void
    {
        $progress = LessonProgress::firstOrNew(['user_id' => $user->id, 'lesson_id' => $lesson->id], ['course_id' => $lesson->course_id]);
        if ($progress->completed_at !== null) {
            return;
        }
        $progress->completed_at = now();
        $progress->save();
        $this->touchCourse($user, $lesson);

        $this->gamification->award($user, 'lesson_completed', 'lesson:'.$lesson->id);
        $this->gamification->grantBadge($user, 'first_lesson');
        $this->gamification->recordActivity($user);

        RecalculateCourseProgress::dispatch($user->id, $lesson->course_id);
    }

    public function touchCourse(User $user, Lesson $lesson): CourseProgress
    {
        $cp = CourseProgress::firstOrNew(['user_id' => $user->id, 'course_id' => $lesson->course_id]);
        $cp->forceFill(['last_lesson_id' => $lesson->id, 'last_activity_at' => now()]);
        if (! $cp->exists) {
            $cp->total_lessons = $lesson->course->lessons()->where('is_optional', false)->count();
        }
        $cp->save();

        return $cp;
    }
}
