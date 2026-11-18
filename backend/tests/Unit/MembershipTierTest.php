<?php

use App\Enums\MembershipTier;
use App\Support\Money;

it('lets higher tiers inherit lower-tier access', function (MembershipTier $have, MembershipTier $need, bool $expected) {
    expect($have->includes($need))->toBe($expected);
})->with([
    [MembershipTier::Starter, MembershipTier::Starter, true],
    [MembershipTier::Starter, MembershipTier::Pro, false],
    [MembershipTier::Pro, MembershipTier::Starter, true],
    [MembershipTier::Pro, MembershipTier::Elite, false],
    [MembershipTier::Elite, MembershipTier::Pro, true],
]);

it('parses levels and slugs', function () {
    expect(MembershipTier::parse('2'))->toBe(MembershipTier::Pro)
        ->and(MembershipTier::parse(3))->toBe(MembershipTier::Elite)
        ->and(MembershipTier::parse('starter'))->toBe(MembershipTier::Starter)
        ->and(fn () => MembershipTier::parse('gold'))->toThrow(ValueError::class);
});

it('formats money from minor units', function () {
    expect(Money::of(49900, 'inr')->toArray())
        ->amount->toBe(49900)->currency->toBe('INR')->formatted->toContain('499');
});
