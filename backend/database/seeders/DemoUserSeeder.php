<?php

namespace Database\Seeders;

use App\Enums\MembershipTier;
use App\Models\Plan;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/** Demo data (non-production): 1 admin, 3 instructors, 20 students. Password for all: "password". */
class DemoUserSeeder extends Seeder
{
    private const INSTRUCTORS = [
        ['instructor@example.com', 'Ivan Instructor', 'Freelance web developer & mentor', ['Web development', 'Freelancing']],
        ['meera@example.com', 'Meera Kapoor', 'Performance marketer, 8 years', ['Digital marketing', 'Meta ads']],
        ['arjun@example.com', 'Arjun Rao', 'YouTube creator with 500k subscribers', ['Video editing', 'Content creation']],
    ];

    public function run(MembershipService $membership): void
    {
        if (User::where('email', 'admin@example.com')->exists()) {
            return; // already seeded
        }

        Event::fakeFor(function () use ($membership) {
            User::factory()->admin()->create(['email' => 'admin@example.com', 'name' => 'Ada Admin']);

            foreach (self::INSTRUCTORS as [$email, $name, $headline, $expertise]) {
                $user = User::factory()->instructor()->create([
                    'email' => $email, 'name' => $name, 'country' => 'IN',
                    'bio' => "{$name} teaches practical, income-focused skills.",
                ]);
                $user->profile()->create(['headline' => $headline, 'expertise' => $expertise, 'is_featured' => true]);
            }

            // 20 students: 4 named demo accounts (one per tier + free) and 16 random.
            foreach (['starter' => MembershipTier::Starter, 'pro' => MembershipTier::Pro, 'elite' => MembershipTier::Elite, 'student' => null] as $handle => $tier) {
                $user = User::factory()->student()->create([
                    'email' => "{$handle}@example.com",
                    'name' => ucfirst($handle).' Student',
                    'country' => 'IN',
                ]);

                if ($tier) {
                    // Demo memberships are complimentary grants (no gateway needed locally).
                    $membership->grantComplimentary($user, Plan::where('tier_level', $tier->value)->firstOrFail(), now()->addYear(), null, 'Demo account');
                }
            }

            User::factory()->count(16)->student()->create();
        });
    }
}
