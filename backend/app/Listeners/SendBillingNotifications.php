<?php

namespace App\Listeners;

use App\Events\PaymentFailed;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionEnded;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\SubscriptionActivatedNotification;
use App\Notifications\Billing\SubscriptionEndedNotification;
use Illuminate\Events\Dispatcher;

/** Customer-facing billing emails (queued notifications on the `mail` queue). */
class SendBillingNotifications
{
    public function activated(SubscriptionActivated $event): void
    {
        $event->subscription->user->notify(new SubscriptionActivatedNotification($event->subscription));
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $event->subscription->user->notify(new PaymentFailedNotification($event->subscription));
    }

    public function ended(SubscriptionEnded $event): void
    {
        $event->subscription->user->notify(new SubscriptionEndedNotification($event->subscription));
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            SubscriptionActivated::class => 'activated',
            PaymentFailed::class => 'paymentFailed',
            SubscriptionEnded::class => 'ended',
        ];
    }
}
