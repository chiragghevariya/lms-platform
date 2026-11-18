<?php

namespace App\Listeners;

use App\Analytics\AnalyticsService;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionEnded;
use App\Jobs\SendServerConversion;
use Illuminate\Events\Dispatcher;

/** Keeps cached analytics fresh and forwards purchases to GA4 / Meta when configured. */
class AnalyticsEvents
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function invalidate(): void
    {
        $this->analytics->invalidate();
    }

    public function paid(PaymentSucceeded $event): void
    {
        $this->analytics->invalidate();
        if ($event->payment->amount > 0 && SendServerConversion::enabled()) {
            SendServerConversion::dispatch($event->payment->id)->afterCommit();
        }
    }

    /** @return array<string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PaymentSucceeded::class => 'paid',
            PaymentRefunded::class => 'invalidate',
            SubscriptionActivated::class => 'invalidate',
            SubscriptionEnded::class => 'invalidate',
        ];
    }
}
