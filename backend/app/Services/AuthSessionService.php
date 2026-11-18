<?php

namespace App\Services;

use App\Enums\LoginEvent;
use App\Models\User;
use App\Security\DeviceLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Dual-mode authentication:
 *  - Web SPA  -> Sanctum stateful cookie session (no token returned).
 *  - Mobile   -> personal access token when `device_name` is supplied.
 */
class AuthSessionService
{
    /** Starts a session (SPA) or issues a token (mobile). Returns the plain token when issued. */
    public function __construct(private readonly LoginActivityRecorder $activity, private readonly DeviceLimiter $devices) {}

    public function start(Request $request, User $user, bool $remember = false, ?string $deviceName = null, ?string $channel = null): ?string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $deviceName ??= $request->string('device_name')->trim()->value();
        $this->activity->record(LoginEvent::Success, $user, channel: $channel ?? ($deviceName !== '' ? 'token' : 'web'));

        if ($deviceName !== '') {
            $token = $user->createToken($deviceName);
            $this->devices->registerToken($request, $user, (int) $token->accessToken->getKey(), $deviceName);

            return $token->plainTextToken;
        }

        Auth::guard('web')->login($user, $remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $this->devices->registerSession($request, $user);
        }

        return null;
    }

    public function end(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        $this->activity->record(LoginEvent::Logout, $user);

        $token = $this->bearerToken($request);

        if ($token !== null) {
            $this->devices->forgetToken((int) $token->getKey());
            $token->delete();

            return;
        }

        if ($request->hasSession()) {
            $device = $request->session()->get(DeviceLimiter::SESSION_KEY);
            $this->devices->forget(is_string($device) ? $device : null);
        }
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** The personal access token used for this request, or null for SPA cookie sessions. */
    public function bearerToken(Request $request): ?PersonalAccessToken
    {
        $plain = $request->bearerToken();

        return $plain ? PersonalAccessToken::findToken($plain) : null;
    }
}
