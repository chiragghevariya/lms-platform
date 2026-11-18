<?php

namespace App\Notifications\Platform;

use App\Models\Payment;
use App\Notify\PlatformNotification;

class SubscriptionRenewedNotification extends PlatformNotification
{
    public function __construct(public Payment $payment) {}

    public function event(): string
    {
        return 'subscription_renewed';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return [
            'plan' => $this->payment->subscription?->plan->name ?? 'membership',
            'amount' => $this->payment->money($this->payment->amount)->formatted(),
            'url' => config('lms.frontend_url').'/app/billing',
        ];
    }
}
