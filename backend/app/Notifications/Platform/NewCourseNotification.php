<?php

namespace App\Notifications\Platform;

use App\Models\Course;
use App\Notify\PlatformNotification;

class NewCourseNotification extends PlatformNotification
{
    public function __construct(public Course $course) {}

    public function event(): string
    {
        return 'new_course';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->course->title, 'url' => config('lms.frontend_url').'/courses/'.$this->course->slug];
    }
}
