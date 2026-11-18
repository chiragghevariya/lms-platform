<?php

namespace App\Notifications\Instructors;

use App\Models\InstructorApplication;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class InstructorApplicationDecidedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public InstructorApplication $application)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'instructor_application';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['url' => config('lms.frontend_url').($this->application->status === 'approved' ? '/instructor' : '/app/become-instructor')];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $approved = $this->application->status === 'approved';
        $mail = (new MailMessage)->subject($approved ? 'Welcome aboard — you\'re an instructor' : 'About your instructor application');
        $approved
            ? $mail->line('Your application was approved. You can now create courses and schedule live classes.')
                ->action('Open the instructor studio', config('lms.frontend_url').'/instructor')
            : $mail->line('Thanks for applying. We can\'t approve your application right now.');
        if ($this->application->review_notes) {
            $mail->line('Notes from our team: '.$this->application->review_notes);
        }

        return $mail;
    }
}
