<?php

namespace App\Jobs;

use App\Models\Course;
use App\Notifications\Platform\NewCourseNotification;
use App\Notify\Segment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/** First publication of a course: tell members whose tier includes it (free courses: everyone active). */
class AnnounceNewCourse implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $courseId) {}

    public function handle(): void
    {
        $course = Course::find($this->courseId);
        if (! $course || ! $course->isPublished()) {
            return;
        }
        $tiers = array_slice(['starter', 'pro', 'elite'], max(0, (int) $course->required_tier - 1));
        $segment = $course->required_tier ? ['tiers' => $tiers] : [];
        Segment::query($segment)->where('id', '!=', $course->instructor_id)
            ->chunkById(500, fn ($users) => Notification::send($users, new NewCourseNotification($course)));
    }
}
