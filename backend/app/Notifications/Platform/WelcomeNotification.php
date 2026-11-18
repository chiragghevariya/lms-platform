<?php

namespace App\Notifications\Platform;

use App\Notify\PlatformNotification;

class WelcomeNotification extends PlatformNotification
{
    public function event(): string
    {
        return 'welcome';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['url' => config('lms.frontend_url').'/courses'];
    }
}
