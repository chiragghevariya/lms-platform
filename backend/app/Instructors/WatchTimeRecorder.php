<?php

namespace App\Instructors;

use App\Courses\CourseAccessService;
use App\Enums\Gateway;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Daily watch time per course. All learners count for analytics (`seconds`); only paid members
 * watching membership courses through their membership count for the earnings pool (`pool_seconds`),
 * capped per member per day. Staff and the course's own instructors never count.
 */
class WatchTimeRecorder
{
    public function __construct(private readonly CourseAccessService $access) {}

    public function record(User $user, Lesson $lesson, int $seconds): void
    {
        $course = $lesson->course;
        if ($seconds <= 0 || $user->isStaff() || $course->isTaughtBy($user)) {
            return;
        }

        $day = now()->toDateString(); // UTC days so monthly pools line up with payment dates
        $pool = $this->poolEligible($user, $lesson) ? min($seconds, max(0, (int) config('earnings.max_counted_seconds_per_user_day')
            - (int) DB::table('course_watch_daily')->where('user_id', $user->id)->where('day', $day)->sum('pool_seconds'))) : 0;

        DB::table('course_watch_daily')->upsert(
            [['course_id' => $course->id, 'user_id' => $user->id, 'day' => $day, 'seconds' => $seconds, 'pool_seconds' => $pool]],
            ['course_id', 'user_id', 'day'],
            // Qualified for Postgres (ambiguous with EXCLUDED); works on MySQL and SQLite too.
            ['seconds' => DB::raw('course_watch_daily.seconds + '.$seconds), 'pool_seconds' => DB::raw('course_watch_daily.pool_seconds + '.$pool)],
        );
    }

    private function poolEligible(User $user, Lesson $lesson): bool
    {
        if ($lesson->course->required_tier === null) {
            return false; // free courses aren't paid for by subscriptions
        }
        $sub = $user->activeSubscription();

        return $sub !== null && $sub->gateway !== Gateway::Manual // complimentary memberships bring no revenue
            && $this->access->accessReason($user, $lesson->course) === 'tier';
    }
}
