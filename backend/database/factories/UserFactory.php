<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('+91 9#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'timezone' => 'UTC',
            'country' => fake()->randomElement(['IN', 'IN', 'IN', 'AE', 'US', 'GB']),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function phoneVerified(): static
    {
        return $this->state(fn () => ['phone' => '+91 9876543210', 'phone_verified_at' => now()]);
    }

    public function role(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    public function student(): static
    {
        return $this->role(Role::Student);
    }

    public function instructor(): static
    {
        return $this->role(Role::Instructor);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    public function affiliate(): static
    {
        return $this->state(fn () => [
            'is_affiliate' => true,
            'affiliate_code' => Str::upper(Str::random(8)),
            'affiliate_activated_at' => now(),
        ]);
    }
}
