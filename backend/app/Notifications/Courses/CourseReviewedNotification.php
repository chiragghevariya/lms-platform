<?php

namespace App\Notifications\Courses;

use App\Models\Course;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class CourseReviewedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public Course $course, public bool $approved)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'course_reviewed';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->course->title, 'url' => $this->approved ? config('lms.frontend_url').'/courses/'.$this->course->slug : config('lms.frontend_url').'/instructor/courses/'.$this->course->uuid.'/edit'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $mail = (new MailMessage)->subject(($this->approved ? 'Published: ' : 'Changes requested: ').$this->course->title);

        return $this->approved
            ? $mail->line('Your course “'.$this->course->title.'” is now live.')->action('View course', config('lms.frontend_url').'/courses/'.$this->course->slug)
            : $mail->line('Your course “'.$this->course->title.'” needs changes before it can be published:')
                ->line((string) $this->course->review_notes)
                ->action('Edit course', config('lms.frontend_url').'/instructor/courses/'.$this->course->uuid.'/edit');
    }
}
