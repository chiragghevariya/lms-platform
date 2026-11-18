<?php

namespace App\Support;

use NumberFormatter;

/**
 * Money is always stored as integer minor units (e.g. paise, cents) + ISO-4217 code.
 */
final class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public function formatted(string $locale = 'en_IN'): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($this->amount / 100, $this->currency);
    }

    /** @return array{amount: int, currency: string, formatted: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->formatted(),
        ];
    }
}
