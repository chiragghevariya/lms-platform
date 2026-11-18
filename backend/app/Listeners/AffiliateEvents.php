<?php

namespace App\Listeners;

use App\Affiliates\CommissionEngine;
use App\Affiliates\ReferralTracker;
use App\Affiliates\StudentReferrals;
use App\Events\PaymentDisputed;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Events\SubscriptionEnded;
use App\Models\User;
use App\Notifications\Platform\CommissionNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

/** Wires referrals into sign-up, payments, refunds/chargebacks and the end of paid plans. */
class AffiliateEvents
{
    public function __construct(
        private readonly ReferralTracker $tracker,
        private readonly CommissionEngine $commissions,
        private readonly StudentReferrals $students,
    ) {}

    public function registered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->tracker->attributeSignup($event->user);
        }
    }

    public function paid(PaymentSucceeded $event): void
    {
        foreach ($this->commissions->onPayment($event->payment) as $c) {
            $c->affiliate->user->notify(new CommissionNotification('earned', $c->amount, $c->currency));
        }
        $this->students->onPayment($event->payment);
    }

    public function refunded(PaymentRefunded $event): void
    {
        $this->commissions->reverse($event->payment, $event->amount, $event->full, 'refund');
        if ($event->full) {
            $this->students->onRefund($event->payment);
        }
    }

    public function disputed(PaymentDisputed $event): void
    {
        $this->commissions->reverse($event->payment, $event->payment->amount, true, 'chargeback');
        $this->students->onRefund($event->payment);
    }

    public function ended(SubscriptionEnded $event): void
    {
        $this->students->applyBanked($event->subscription);
    }

    /** @return array<string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Registered::class => 'registered',
            PaymentSucceeded::class => 'paid',
            PaymentRefunded::class => 'refunded',
            PaymentDisputed::class => 'disputed',
            SubscriptionEnded::class => 'ended',
        ];
    }
}
