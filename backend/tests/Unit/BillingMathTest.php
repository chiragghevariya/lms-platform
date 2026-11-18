<?php

use App\Billing\TaxCalculator;
use App\Enums\CouponType;
use App\Models\Coupon;

it('splits GST out of tax-inclusive Indian prices', function () {
    config(['billing.tax.rates' => ['IN' => ['name' => 'GST', 'rate' => 18.0]]]);

    expect((new TaxCalculator)->breakdown(118000, 'IN'))
        ->toBe(['subtotal' => 100000, 'tax_amount' => 18000, 'tax_rate' => 18.0, 'tax_name' => 'GST'])
        ->and((new TaxCalculator)->breakdown(5000, 'US'))
        ->toMatchArray(['subtotal' => 5000, 'tax_amount' => 0, 'tax_name' => null]);
});

it('computes coupon discounts without exceeding the price', function () {
    $pct = new Coupon(['type' => CouponType::Percent, 'value' => 25]);
    $fixed = new Coupon(['type' => CouponType::Fixed, 'value' => 50000, 'currency' => 'INR']);

    expect($pct->discountFor(99900, 'INR'))->toBe(24975)
        ->and($fixed->discountFor(30000, 'INR'))->toBe(30000)
        ->and($fixed->discountFor(30000, 'USD'))->toBe(0);
});
