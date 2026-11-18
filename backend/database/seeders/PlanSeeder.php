<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Starter / Pro / Elite with INR (Razorpay) + USD (Stripe) monthly & yearly prices, plus an Elite lifetime option. */
class PlanSeeder extends Seeder
{
    private const FEATURES = [
        'courses' => 'Course library',
        'certificates' => 'Certificates of completion',
        'community' => 'Community access',
        'live' => 'Live classes',
        'resources' => 'Downloadable resources',
        'mentor' => '1:1 mentor sessions',
        'support' => 'Support',
    ];

    private const MATRIX = [
        1 => ['courses' => 'Starter courses', 'certificates' => true, 'community' => true, 'live' => false, 'resources' => false, 'mentor' => false, 'support' => 'Email'],
        2 => ['courses' => 'Starter + Pro courses', 'certificates' => true, 'community' => true, 'live' => 'Monthly', 'resources' => true, 'mentor' => false, 'support' => 'Email'],
        3 => ['courses' => 'Every course', 'certificates' => true, 'community' => true, 'live' => 'Weekly', 'resources' => true, 'mentor' => true, 'support' => 'Priority'],
    ];

    /** [tier => [currency => [month, year, lifetime|null]]] in minor units. */
    private const PRICES = [
        1 => ['INR' => [49900, 499000, null], 'USD' => [900, 9000, null]],
        2 => ['INR' => [99900, 999000, null], 'USD' => [1900, 19000, null]],
        3 => ['INR' => [199900, 1999000, 4999900], 'USD' => [3900, 39000, 79900]],
    ];

    public function run(): void
    {
        foreach ([1 => ['Starter', 'starter'], 2 => ['Pro', 'pro'], 3 => ['Elite', 'elite']] as $level => [$name, $slug]) {
            $plan = Plan::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'tier_level' => $level,
                'description' => match ($level) {
                    1 => 'Start building in-demand skills.',
                    2 => 'Go deeper with live classes and resources.',
                    default => 'Everything, plus mentorship.',
                },
                'features' => collect(self::FEATURES)->map(fn ($label, $key) => [
                    'key' => $key, 'label' => $label, 'value' => self::MATRIX[$level][$key],
                ])->values()->all(),
                'is_active' => true,
                'sort' => $level * 10,
            ]);

            foreach (self::PRICES[$level] as $currency => [$month, $year, $lifetime]) {
                foreach ([[BillingInterval::Month, $month, 0], [BillingInterval::Year, $year, 0], [BillingInterval::Lifetime, $lifetime, 0]] as [$interval, $amount, $trial]) {
                    if ($amount === null) {
                        continue;
                    }
                    $plan->prices()->updateOrCreate(
                        ['interval' => $interval, 'currency' => $currency],
                        ['amount' => $amount, 'trial_days' => $level === 1 && $interval === BillingInterval::Month ? 7 : $trial, 'is_active' => true],
                    );
                }
            }
        }
    }
}
