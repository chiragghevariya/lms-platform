<?php

namespace App\Notifications\Instructors;

use App\Models\CourseAnnouncement;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

class CourseAnnouncementNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public CourseAnnouncement $announcement)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'course_announcement';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->announcement->course->title, 'title' => $this->announcement->title, 'url' => config('lms.frontend_url').'/courses/'.$this->announcement->course->slug];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $a = $this->announcement;

        return (new MailMessage)
            ->subject($a->course->title.': '.$a->title)
            ->greeting($a->title)
            ->line(new HtmlString($a->body)) // sanitised on write (RichText)
            ->action('Open the course', config('lms.frontend_url').'/courses/'.$a->course->slug)
            ->salutation('— '.$a->author->name);
    }
}
