<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\BeginSession;
use App\Enums\LoginEvent;
use App\Exceptions\AuthFlowException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginActivityRecorder;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorChallengeController extends Controller
{
    public function __invoke(Request $request, TwoFactorService $twoFactor, BeginSession $begin, LoginActivityRecorder $activity): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $token = $data['challenge_token'];
        $challenge = $twoFactor->challenge($token);
        $user = $challenge ? User::find($challenge['user_id']) : null;

        if (! $challenge || ! $user || ! $user->status->canSignIn()) {
            throw new AuthFlowException('This sign-in attempt has expired. Please log in again.', 'two_factor_expired', 410);
        }

        if (! $twoFactor->verify($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $activity->record(LoginEvent::TwoFactorFailed, $user, channel: $challenge['channel']);
            $stillValid = $twoFactor->failChallenge($token);

            throw $stillValid
                ? new AuthFlowException('That code is not valid.', 'two_factor_invalid')
                : new AuthFlowException('Too many invalid codes. Please log in again.', 'two_factor_expired', 410);
        }

        $twoFactor->forgetChallenge($token);

        return response()->json(['data' => $begin->handle(
            $request, $user, $challenge['remember'], $challenge['device_name'], $challenge['channel'], skipTwoFactor: true,
        )]);
    }
}
