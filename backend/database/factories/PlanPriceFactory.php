<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanPrice>
 */
class PlanPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'interval' => BillingInterval::Month,
            'amount' => fake()->numberBetween(5, 50) * 10000,
            'currency' => 'INR',
            'trial_days' => 0,
            'is_active' => true,
        ];
    }

    public function interval(BillingInterval $interval): static
    {
        return $this->state(fn () => ['interval' => $interval]);
    }

    /** INR price wired to a Razorpay plan. */
    public function razorpay(): static
    {
        return $this->state(fn () => ['currency' => 'INR', 'razorpay_plan_id' => 'plan_'.Str::random(14)]);
    }

    /** USD price wired to a Stripe price. */
    public function stripe(): static
    {
        return $this->state(fn () => ['currency' => 'USD', 'amount' => fake()->numberBetween(5, 50) * 100, 'stripe_price_id' => 'price_'.Str::random(14)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
