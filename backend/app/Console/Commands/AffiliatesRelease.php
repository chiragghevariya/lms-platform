<?php

namespace App\Console\Commands;

use App\Affiliates\CommissionEngine;
use App\Affiliates\StudentReferrals;
use Illuminate\Console\Command;

class AffiliatesRelease extends Command
{
    protected $signature = 'affiliates:release';

    protected $description = 'Approve commissions and student rewards whose hold period has passed';

    public function handle(CommissionEngine $commissions, StudentReferrals $students): int
    {
        $this->info('commissions approved: '.$commissions->release().' · rewards granted: '.$students->release());

        return self::SUCCESS;
    }
}
