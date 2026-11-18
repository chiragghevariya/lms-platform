<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(8)),
            'type' => CouponType::Percent,
            'value' => 20,
            'is_active' => true,
        ];
    }

    public function fixed(int $amount, string $currency = 'INR'): static
    {
        return $this->state(fn () => ['type' => CouponType::Fixed, 'value' => $amount, 'currency' => $currency]);
    }
}
