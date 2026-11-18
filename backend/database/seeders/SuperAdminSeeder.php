<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Safe for production: credentials come from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD. */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) config('lms.super_admin.email'));
        $password = config('lms.super_admin.password');

        if (app()->isProduction() && (! is_string($password) || strlen($password) < 12)) {
            throw new RuntimeException('Set SUPER_ADMIN_PASSWORD (min 12 chars) before seeding production.');
        }

        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->fill(['name' => 'Super Admin', 'password' => is_string($password) ? $password : 'password']);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->syncRoles([Role::SuperAdmin->value]);
    }
}
