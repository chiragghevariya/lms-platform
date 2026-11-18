<?php

namespace App\Notifications\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;

class SubscriptionEndedNotification extends BillingNotification
{
    public function __construct(public Subscription $subscription)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'subscription_cancelled';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['plan' => $this->subscription->plan->name, 'url' => config('lms.frontend_url').'/pricing'];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $reason = $this->subscription->status === SubscriptionStatus::Expired && $this->subscription->past_due_since
            ? 'because we could not collect payment'
            : 'as scheduled';

        return (new MailMessage)
            ->subject('Your membership has ended')
            ->line('Your '.$this->subscription->plan->name." membership has ended {$reason}.")
            ->line('Your progress is saved — resubscribe any time to pick up where you left off.')
            ->action('See plans', config('lms.frontend_url').'/pricing');
    }
}
