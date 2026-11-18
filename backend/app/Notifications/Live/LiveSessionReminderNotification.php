<?php

namespace App\Notifications\Live;

use App\Models\LiveSession;
use Illuminate\Notifications\Messages\MailMessage;

class LiveSessionReminderNotification extends LiveNotification
{
    public function __construct(LiveSession $session, public int $minutesBefore)
    {
        parent::__construct($session);
    }

    public function event(): string
    {
        return $this->minutesBefore <= 60 ? 'live_reminder_15m' : 'live_reminder_24h';
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $soon = $this->minutesBefore <= 60;

        return (new MailMessage)
            ->subject(($soon ? 'Starting in '.$this->minutesBefore.' minutes: ' : 'Tomorrow: ').$this->session->title)
            ->line('“'.$this->session->title.'” starts '.($soon ? 'soon' : 'in about 24 hours').'.')
            ->line('When: '.$this->when($notifiable))
            ->action($soon ? 'Join now' : 'Open the live room', $this->roomUrl());
    }
}
