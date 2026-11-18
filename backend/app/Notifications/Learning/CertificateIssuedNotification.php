<?php

namespace App\Notifications\Learning;

use App\Models\Certificate;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class CertificateIssuedNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public Certificate $certificate)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'certificate_issued';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->certificate->course_title, 'code' => $this->certificate->verification_code, 'url' => config('lms.frontend_url').'/app/certificates'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return (new MailMessage)
            ->subject('Your certificate: '.$this->certificate->course_title)
            ->greeting('Congratulations!')
            ->line('You completed “'.$this->certificate->course_title.'”.')
            ->line('Certificate ID: '.$this->certificate->verification_code)
            ->action('View your certificate', config('lms.frontend_url').'/app/certificates');
    }
}
