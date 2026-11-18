<?php

namespace App\Billing;

/**
 * Tax breakdown for tax-inclusive prices (GST/VAT-ready).
 * Listed prices include tax; the invoice shows the subtotal + tax split.
 */
class TaxCalculator
{
    /** @return array{subtotal: int, tax_amount: int, tax_rate: float, tax_name: string|null} */
    public function breakdown(int $total, ?string $country): array
    {
        /** @var array{name: string, rate: float}|null $rule */
        $rule = config('billing.tax.rates.'.strtoupper((string) $country));

        if (! $rule || $rule['rate'] <= 0) {
            return ['subtotal' => $total, 'tax_amount' => 0, 'tax_rate' => 0.0, 'tax_name' => null];
        }

        $subtotal = (int) round($total * 100 / (100 + $rule['rate']));

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $total - $subtotal,
            'tax_rate' => (float) $rule['rate'],
            'tax_name' => $rule['name'],
        ];
    }
}
