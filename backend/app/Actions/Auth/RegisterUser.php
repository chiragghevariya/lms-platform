<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    /** @param  array{name: string, email: string, password: string, phone?: string|null, timezone?: string|null}  $data */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'timezone' => $data['timezone'] ?? 'UTC',
            ]);

            $user->assignRole(Role::Student->value);

            return $user;
        });

        // Side effects (verification email, future welcome WhatsApp, CRM sync…) hang off this event.
        event(new Registered($user));

        return $user;
    }
}
