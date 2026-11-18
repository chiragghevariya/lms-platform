<?php

use App\Enums\BillingInterval;
use App\Models\User;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed(PlanSeeder::class));

it('lists active plans with prices in the requested currency', function () {
    $res = $this->getJson(api('plans?currency=USD'))->assertOk()
        ->assertJsonCount(3, 'data')->assertJsonPath('meta.currency', 'USD');

    expect($res->json('data.0.tier'))->toBe('starter')
        ->and($res->json('data.0.tier_level'))->toBe(1)
        ->and(collect($res->json('data.2.prices'))->pluck('interval')->all())->toContain('month', 'year', 'lifetime')
        ->and($res->json('data.0.prices.0.gateway'))->toBe('stripe')
        ->and($res->json('data.0.features.0'))->toHaveKeys(['key', 'label', 'value']);
});

it('defaults currency by the user country: IN → INR (Razorpay)', function () {
    $this->actingAs(User::factory()->create(['country' => 'IN']))->getJson(api('plans'))
        ->assertJsonPath('meta.currency', 'INR')->assertJsonPath('data.0.prices.0.gateway', 'razorpay');
    $this->actingAs(User::factory()->create(['country' => 'US']))->getJson(api('plans'))
        ->assertJsonPath('meta.currency', 'USD');
});

it('hides gateway ids from the public', function () {
    $this->getJson(api('plans'))->assertJsonMissingPath('data.0.prices.0.stripe_price_id');
});

it('shows one plan by slug', function () {
    $this->getJson(api('plans/pro'))->assertOk()->assertJsonPath('data.tier_level', 2);
    $this->getJson(api('plans/nope'))->assertNotFound();
});

it('seeds a 7-day trial on Starter monthly and an Elite lifetime option', function () {
    $res = $this->getJson(api('plans?currency=INR'))->json('data');
    $starterMonth = collect($res[0]['prices'])->firstWhere('interval', BillingInterval::Month->value);

    expect($starterMonth['trial_days'])->toBe(7)
        ->and(collect($res[2]['prices'])->firstWhere('interval', 'lifetime'))->not->toBeNull();
});
