<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';
    case Lifetime = 'lifetime';

    public function isRecurring(): bool
    {
        return $this !== self::Lifetime;
    }

    public function months(): ?int
    {
        return match ($this) {
            self::Month => 1,
            self::Year => 12,
            self::Lifetime => null,
        };
    }
}
