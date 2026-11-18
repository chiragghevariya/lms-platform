<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;

class SubscriptionActivatedNotification extends BillingNotification
{
    public function __construct(public Subscription $subscription)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'subscription_started';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['plan' => $this->subscription->plan->name, 'url' => config('lms.frontend_url').'/courses'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $plan = $this->subscription->plan->name;
        $until = $this->subscription->is_lifetime ? 'for life' : ($this->subscription->current_period_end ? 'until '.$this->subscription->current_period_end->toFormattedDayDateString() : '');

        return (new MailMessage)
            ->subject("Your {$plan} membership is active")
            ->greeting('Welcome aboard!')
            ->line("Your {$plan} membership is active {$until}.")
            ->action('Start learning', config('lms.frontend_url').'/app');
    }
}
