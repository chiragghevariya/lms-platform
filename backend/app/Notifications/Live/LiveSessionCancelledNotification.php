<?php

namespace App\Notifications\Live;

use Illuminate\Notifications\Messages\MailMessage;

class LiveSessionCancelledNotification extends LiveNotification
{
    public function event(): string
    {
        return 'live_cancelled';
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Cancelled: '.$this->session->title)
            ->line('“'.$this->session->title.'” on '.$this->when($notifiable).' has been cancelled.');
        if ($this->session->cancel_reason) {
            $mail->line('Reason: '.$this->session->cancel_reason);
        }

        return $mail->action('See other live classes', config('lms.frontend_url').'/app/live');
    }
}
