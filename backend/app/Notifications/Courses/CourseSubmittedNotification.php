<?php

namespace App\Notifications\Courses;

use App\Models\Course;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class CourseSubmittedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public Course $course)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'course_submitted';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->course->title, 'url' => config('lms.frontend_url').'/admin/courses'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return (new MailMessage)
            ->subject('Course submitted for review: '.$this->course->title)
            ->line($this->course->instructor->name.' submitted “'.$this->course->title.'” for review.')
            ->action('Review course', config('lms.frontend_url').'/admin/courses?status=review');
    }
}
