<?php

namespace App\Listeners;

use App\Events\LiveSessionReminderDue;
use App\Notifications\Live\LiveSessionReminderNotification;

class SendLiveReminder
{
    public function handle(LiveSessionReminderDue $event): void
    {
        $event->registration->user->notify(new LiveSessionReminderNotification($event->registration->session, $event->minutesBefore));
    }
}
