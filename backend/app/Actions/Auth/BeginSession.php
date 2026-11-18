<?php

namespace App\Actions\Auth;

use App\Enums\LoginEvent;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\LoginActivityRecorder;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;

/**
 * Final step of every sign-in path (password, Google, 2FA challenge):
 * either hands back a 2FA challenge or starts the session / issues the token.
 */
class BeginSession
{
    public function __construct(
        private readonly AuthSessionService $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly LoginActivityRecorder $activity,
    ) {}

    /** @return array{two_factor_required: bool, challenge_token: string|null, user: UserResource|null, token: string|null, token_type: string|null} */
    public function handle(Request $request, User $user, bool $remember, ?string $deviceName, string $channel, bool $skipTwoFactor = false): array
    {
        $deviceName = $deviceName !== null && trim($deviceName) !== '' ? trim($deviceName) : null;

        if (! $skipTwoFactor && $user->hasTwoFactorEnabled()) {
            $this->activity->record(LoginEvent::TwoFactorChallenged, $user, channel: $channel);

            return [
                'two_factor_required' => true,
                'challenge_token' => $this->twoFactor->createChallenge($user, [
                    'remember' => $remember, 'device_name' => $deviceName, 'channel' => $channel,
                ]),
                'user' => null,
                'token' => null,
                'token_type' => null,
            ];
        }

        $token = $this->sessions->start($request, $user, $remember, $deviceName ?? '', $channel);

        return [
            'two_factor_required' => false,
            'challenge_token' => null,
            'user' => new UserResource($user->fresh() ?? $user),
            'token' => $token,
            'token_type' => $token ? 'Bearer' : null,
        ];
    }
}
