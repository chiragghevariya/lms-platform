<?php

namespace App\Console\Commands;

use App\Instructors\EarningsService;
use Illuminate\Console\Command;

class EarningsGenerate extends Command
{
    protected $signature = 'earnings:generate {period? : YYYY-MM, defaults to last month}';

    protected $description = 'Compute (or refresh) draft instructor payout statements for a closed month';

    public function handle(EarningsService $earnings): int
    {
        $period = (string) ($this->argument('period') ?? now()->utc()->subMonthNoOverflow()->format('Y-m'));
        $this->info("{$period}: ".$earnings->generate($period).' statement(s) written');

        return self::SUCCESS;
    }
}
