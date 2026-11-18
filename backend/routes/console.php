<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing: dunning (grace → downgrade), lapsed memberships, scheduled downgrades, deferred Razorpay cancellations.
Schedule::command('billing:maintain')->hourly()->withoutOverlapping()->onOneServer();

// Live classes: status changes without webhooks, 24h / 15min reminders, attendance sync fallback.
Schedule::command('live:maintain')->everyMinute()->withoutOverlapping()->onOneServer();

// Instructor earnings: draft statements for last month (admins review, finalize and mark paid).
Schedule::command('earnings:generate')->monthlyOn(1, '04:00')->onOneServer();

// Affiliates: release held commissions/rewards; monthly payout batch on the 5th.
Schedule::command('affiliates:release')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('affiliates:payouts')->monthlyOn(5, '05:00')->onOneServer();

// Notifications: expiring memberships, inactivity nudges, scheduled campaigns.
Schedule::command('notify:scheduled')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Analytics: nightly MRR/member snapshots + cache warm-up for the admin dashboard.
Schedule::command('analytics:snapshot')->dailyAt('01:30')->timezone(config('analytics.timezone'))->onOneServer();
