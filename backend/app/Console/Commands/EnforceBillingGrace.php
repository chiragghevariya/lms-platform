<?php

namespace App\Console\Commands;

use App\Billing\BillingService;
use App\Billing\SubscriptionSync;
use Illuminate\Console\Command;

class EnforceBillingGrace extends Command
{
    protected $signature = 'billing:maintain';

    protected $description = 'Dunning + safety net: downgrade past-due after grace, end lapsed memberships, apply due plan changes, forward Razorpay cancellations';

    public function handle(SubscriptionSync $sync, BillingService $billing): int
    {
        $expired = $sync->expireAfterGrace();
        $reconciled = $sync->reconcileLapsed();
        $forwarded = $billing->forwardRazorpayCancellations();

        $this->info("Expired after grace: {$expired}; reconciled: {$reconciled}; Razorpay cancellations sent: {$forwarded}");

        return self::SUCCESS;
    }
}
