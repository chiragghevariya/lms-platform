<?php

namespace App\Notifications\Live;

use App\Live\CalendarLinks;
use Illuminate\Notifications\Messages\MailMessage;

class LiveRegistrationConfirmedNotification extends LiveNotification
{
    public function event(): string
    {
        return 'live_registered';
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $calendar = app(CalendarLinks::class);

        return (new MailMessage)
            ->subject('You\'re registered: '.$this->session->title)
            ->line('You\'re registered for “'.$this->session->title.'”.')
            ->line('When: '.$this->when($notifiable).' ('.$this->session->duration.' min)')
            ->line('You can join from 10 minutes before the start.')
            ->action('Open the live room', $this->roomUrl())
            ->line('[Add to Google Calendar]('.$calendar->google($this->session).') — or open the attached invite.')
            ->attachData($calendar->ics([$this->session], $this->session->title), 'invite.ics', ['mime' => 'text/calendar; charset=utf-8; method=PUBLISH']);
    }
}
