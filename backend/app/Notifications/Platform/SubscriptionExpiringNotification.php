<?php

namespace App\Notifications\Platform;

use App\Models\Subscription;
use App\Models\User;
use App\Notify\PlatformNotification;

class SubscriptionExpiringNotification extends PlatformNotification
{
    public function __construct(public Subscription $subscription, public int $days) {}

    public function event(): string
    {
        return $this->days <= 1 ? 'subscription_expiring_1d' : 'subscription_expiring_7d';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        $tz = $notifiable instanceof User && $notifiable->timezone ? $notifiable->timezone : 'UTC';

        return [
            'plan' => $this->subscription->plan->name,
            'date' => $this->subscription->current_period_end?->copy()->setTimezone($tz)->format('j M Y'),
            'url' => config('lms.frontend_url').'/pricing',
        ];
    }
}
