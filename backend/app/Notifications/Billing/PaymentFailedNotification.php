<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;

/** Dunning email: renewal failed; access continues during the grace period. */
class PaymentFailedNotification extends BillingNotification
{
    public function __construct(public Subscription $subscription)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'payment_failed';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['plan' => $this->subscription->plan->name, 'url' => $this->billingUrl()];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        $days = (int) config('billing.grace_days');
        $deadline = ($this->subscription->past_due_since ?? now())->copy()->addDays($days)->toFormattedDayDateString();

        return (new MailMessage)
            ->subject('Action needed: your membership payment failed')
            ->line('We could not renew your '.$this->subscription->plan->name.' membership.')
            ->line("We'll retry automatically. Your access continues until {$deadline}; after that your membership will be downgraded.")
            ->action('Update payment method', $this->billingUrl());
    }
}
