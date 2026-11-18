<?php

namespace App\Console\Commands;

use App\Affiliates\Payouts\PayoutService;
use Illuminate\Console\Command;

class AffiliatesPayouts extends Command
{
    protected $signature = 'affiliates:payouts {period? : YYYY-MM label for the batch, defaults to this month}';

    protected $description = 'Create the monthly affiliate payout batch (balances above the minimum)';

    public function handle(PayoutService $payouts): int
    {
        $period = (string) ($this->argument('period') ?? now()->format('Y-m'));
        $this->info("{$period}: ".$payouts->batch($period).' payout(s) created');

        return self::SUCCESS;
    }
}
