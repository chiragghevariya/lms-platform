<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Platform\SubscriptionRenewedNotification;
use App\Notifications\Platform\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

/** Events that produce notifications without a dedicated place in the domain code. */
class NotificationEvents
{
    public function registered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $event->user->notify(new WelcomeNotification);
        }
    }

    /** Renewals: every succeeded subscription payment after the first one. */
    public function paid(PaymentSucceeded $event): void
    {
        $p = $event->payment;
        if (! $p->subscription_id || $p->course_id) {
            return;
        }
        $earlier = Payment::where('subscription_id', $p->subscription_id)->where('status', 'succeeded')->where('id', '<', $p->id)->exists();
        if ($earlier) {
            $p->loadMissing('subscription.plan', 'user');
            $p->user->notify(new SubscriptionRenewedNotification($p));
        }
    }

    /** @return array<string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [Registered::class => 'registered', PaymentSucceeded::class => 'paid'];
    }
}
