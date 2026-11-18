<?php

namespace App\Actions\Auth;

use App\Enums\LoginEvent;
use App\Exceptions\AuthFlowException;
use App\Models\User;
use App\Services\LoginActivityRecorder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies credentials with per-account lockout (on top of the IP/email rate limiter):
 * after `max_login_attempts` consecutive failures the account is locked for `lockout_minutes`.
 */
class AuthenticateUser
{
    public function __construct(private readonly LoginActivityRecorder $activity) {}

    /** @throws ValidationException|AuthFlowException */
    public function handle(string $email, string $password): User
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if (! $user) {
            Hash::check($password, self::dummyHash()); // equalise timing with the real check
            $this->activity->record(LoginEvent::Failed, email: $email);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->isLocked()) {
            $this->activity->record(LoginEvent::LockedOut, $user);

            throw AuthFlowException::locked((int) now()->diffInSeconds($user->locked_until, true));
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->status->canSignIn()) {
            $this->activity->record(LoginEvent::Blocked, $user);

            throw AuthFlowException::blocked();
        }

        if ($user->failed_login_attempts > 0 || $user->locked_until) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        }

        return $user;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equaliser');
    }

    private function registerFailure(User $user): void
    {
        $max = (int) config('lms.security.max_login_attempts');
        $attempts = $user->failed_login_attempts + 1;
        $lock = $attempts >= $max;

        $user->forceFill([
            'failed_login_attempts' => $lock ? 0 : $attempts,
            'locked_until' => $lock ? now()->addMinutes((int) config('lms.security.lockout_minutes')) : null,
        ])->save();

        $this->activity->record($lock ? LoginEvent::LockedOut : LoginEvent::Failed, $user);

        if ($lock) {
            throw AuthFlowException::locked((int) config('lms.security.lockout_minutes') * 60);
        }
    }
}
