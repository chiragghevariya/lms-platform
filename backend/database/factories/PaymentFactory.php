<?php

namespace Database\Factories;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gateway' => Gateway::Razorpay,
            'gateway_payment_id' => 'pay_'.Str::random(14),
            'status' => PaymentStatus::Succeeded,
            'currency' => 'INR',
            'amount' => 99900,
            'subtotal' => 84661,
            'tax_amount' => 15239,
            'tax_rate' => '18.00',
            'tax_name' => 'GST',
            'description' => 'Pro membership (month)',
            'invoice_number' => 'INV-TEST-'.Str::upper(Str::random(6)),
            'paid_at' => now(),
        ];
    }
}
