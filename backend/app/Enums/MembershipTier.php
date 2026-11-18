<?php

namespace App\Enums;

/** Tier level 1-3 (plans.tier_level / content required_tier). Higher includes lower. */
enum MembershipTier: int
{
    case Starter = 1;
    case Pro = 2;
    case Elite = 3;

    public function slug(): string
    {
        return strtolower($this->name);
    }

    public function label(): string
    {
        return $this->name;
    }

    public function includes(self $required): bool
    {
        return $this->value >= $required->value;
    }

    /** Accepts a level ("2") or slug ("pro"). */
    public static function parse(string|int $value): self
    {
        if (is_int($value) || ctype_digit($value)) {
            return self::from((int) $value);
        }

        foreach (self::cases() as $case) {
            if ($case->slug() === strtolower($value)) {
                return $case;
            }
        }

        throw new \ValueError("Unknown membership tier [{$value}].");
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(fn (self $t) => $t->slug(), self::cases());
    }
}
