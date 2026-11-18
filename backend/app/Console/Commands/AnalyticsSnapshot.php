<?php

namespace App\Console\Commands;

use App\Analytics\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Nightly: store yesterday's MRR / members snapshot and pre-warm the dashboard ranges. */
class AnalyticsSnapshot extends Command
{
    protected $signature = 'analytics:snapshot {--day= : Local date to snapshot (default: yesterday)}';

    protected $description = 'Snapshot daily business metrics and warm the analytics cache';

    public function handle(AnalyticsService $analytics): int
    {
        $tz = (string) config('analytics.timezone');
        $day = $this->option('day') ? Carbon::parse((string) $this->option('day'), $tz) : null;
        $written = $analytics->snapshot($day);

        $today = $analytics->today();
        foreach ($analytics->currencies() as $currency) {
            foreach ([7, 30, 90, 365] as $days) {
                $from = now($tz)->subDays($days - 1)->toDateString();
                $analytics->summary($from, $today, $currency);
                $analytics->revenue($from, $today, $currency);
                $analytics->learning($from, $today, $currency);
                $analytics->affiliates($from, $today, $currency);
            }
        }
        $analytics->cohorts();

        $this->info("Wrote {$written} snapshot rows and warmed the cache.");

        return self::SUCCESS;
    }
}
