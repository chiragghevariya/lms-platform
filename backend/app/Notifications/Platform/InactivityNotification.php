<?php

namespace App\Notifications\Platform;

use App\Notify\PlatformNotification;

class InactivityNotification extends PlatformNotification
{
    public function __construct(public int $days, public ?string $course = null, public ?string $url = null) {}

    public function event(): string
    {
        return $this->days >= 14 ? 'inactivity_14d' : 'inactivity_7d';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['course' => $this->course ?? 'your course', 'url' => $this->url ?? config('lms.frontend_url').'/app'];
    }
}
