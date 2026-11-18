<?php

namespace Database\Factories;

use App\Enums\MembershipTier;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $tier = fake()->randomElement(MembershipTier::cases());

        return [
            'name' => $tier->label(),
            'slug' => $tier->slug().'-'.Str::lower(Str::random(6)),
            'tier_level' => $tier->value,
            'description' => fake()->sentence(),
            'features' => [['key' => 'courses', 'label' => $tier->label().' courses', 'value' => true]],
            'is_active' => true,
            'sort' => $tier->value * 10,
        ];
    }

    public function tier(MembershipTier $tier): static
    {
        return $this->state(fn () => ['tier_level' => $tier->value, 'name' => $tier->label(), 'sort' => $tier->value * 10]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
