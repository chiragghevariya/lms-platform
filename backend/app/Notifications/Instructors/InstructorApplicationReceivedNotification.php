<?php

namespace App\Notifications\Instructors;

use App\Models\InstructorApplication;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class InstructorApplicationReceivedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public InstructorApplication $application)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'instructor_applied';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['name' => $this->application->user->name, 'url' => config('lms.frontend_url').'/admin/instructor-applications'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return (new MailMessage)
            ->subject('New instructor application: '.$this->application->user->name)
            ->line($this->application->user->name.' applied to teach: “'.$this->application->headline.'”.')
            ->action('Review applications', config('lms.frontend_url').'/admin/instructor-applications');
    }
}
