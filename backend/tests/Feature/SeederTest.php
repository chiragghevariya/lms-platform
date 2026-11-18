<?php

use App\Enums\MembershipTier;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds roles, plans and demo users idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Plan::count())->toBe(3)
        ->and(PlanPrice::count())->toBe(14)
        ->and(User::whereEmail('pro@example.com')->first()->currentTier())->toBe(MembershipTier::Pro)
        ->and(User::whereEmail('superadmin@example.com')->first()->hasRole('super_admin'))->toBeTrue()
        ->and(User::role('super_admin')->count())->toBe(1)
        ->and(User::role('instructor')->count())->toBe(3)
        ->and(User::role('student')->count())->toBe(20);
});
