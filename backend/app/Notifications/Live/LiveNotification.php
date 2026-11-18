<?php

namespace App\Notifications\Live;

use App\Live\CalendarLinks;
use App\Models\LiveSession;
use App\Models\User;
use App\Notify\PlatformNotification;
use Illuminate\Bus\Queueable;

abstract class LiveNotification extends PlatformNotification
{
    use Queueable;

    public function __construct(public LiveSession $session)
    {
        $this->onQueue('mail');
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['title' => $this->session->title, 'when' => $this->when($notifiable), 'url' => $this->roomUrl()];
    }

    /** Start time in the learner's own timezone. */
    protected function when(object $notifiable): string
    {
        $tz = $notifiable instanceof User && $notifiable->timezone ? $notifiable->timezone : $this->session->timezone;

        return $this->session->starts_at->copy()->setTimezone($tz)->format('D j M Y, g:i A T');
    }

    protected function roomUrl(): string
    {
        return app(CalendarLinks::class)->roomUrl($this->session);
    }
}
