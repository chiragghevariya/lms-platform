<?php

namespace Database\Factories;

use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_price_id' => PlanPrice::factory()->razorpay(),
            'plan_id' => fn (array $a) => PlanPrice::findOrFail($a['plan_price_id'])->plan_id,
            'gateway' => Gateway::Razorpay,
            'gateway_subscription_id' => 'sub_'.Str::random(14),
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ];
    }

    public function forPrice(PlanPrice $price): static
    {
        return $this->state(fn () => [
            'plan_price_id' => $price->id,
            'plan_id' => $price->plan_id,
            'gateway' => $price->currency === 'INR' ? Gateway::Razorpay : Gateway::Stripe,
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn () => ['gateway' => Gateway::Stripe, 'gateway_subscription_id' => 'sub_'.Str::random(14)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
            'ended_at' => now()->subMonth(),
        ]);
    }

    public function pastDue(int $daysAgo = 1): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::PastDue, 'past_due_since' => now()->subDays($daysAgo), 'current_period_end' => now()->subDays($daysAgo)]);
    }

    public function complimentary(): static
    {
        return $this->state(fn () => ['gateway' => Gateway::Manual, 'gateway_subscription_id' => null, 'plan_price_id' => null, 'current_period_end' => null]);
    }
}
