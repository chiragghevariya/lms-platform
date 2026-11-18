<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Exceptions\AuthFlowException;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Finds or creates the local account for a Google identity.
 * Links an existing email account only when Google reports the email as verified.
 */
class ResolveGoogleUser
{
    public function handle(SocialiteUser $google): User
    {
        $email = strtolower((string) $google->getEmail());
        /** @var array<string, mixed> $raw */
        $raw = method_exists($google, 'getRaw') ? $google->getRaw() : [];
        $verified = (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);

        if ($email === '' || ! $verified) {
            throw new AuthFlowException('Your Google account email is not verified.', 'google_email_unverified');
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            if (! $user->status->canSignIn()) {
                throw AuthFlowException::blocked();
            }

            $user->forceFill([
                'google_id' => $user->google_id ?? (string) $google->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return $user;
        }

        $user = DB::transaction(function () use ($google, $email) {
            $user = new User([
                'name' => $google->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(40), // unusable until reset; Google is the sign-in method
            ]);
            $user->forceFill(['google_id' => (string) $google->getId(), 'email_verified_at' => now()])->save();
            $user->assignRole(Role::Student->value);

            return $user;
        });
        // New account: same hooks as form sign-up (referral attribution). Already verified, so no verification email.
        event(new Registered($user));

        return $user;
    }
}
