<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Optional TOTP 2FA for staff accounts. Sensitive actions require the current password. */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'available' => $user->canUseTwoFactor(),
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending_confirmation' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
            'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
        ]]);
    }

    /** Step 1: generate secret + QR. Returns the recovery codes once. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canUseTwoFactor(), 403, 'Two-factor authentication is available for admin accounts.');
        $request->validate(['password' => ['required', 'current_password']]);

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => 'Two-factor authentication is already enabled.']);
        }

        $this->twoFactor->beginEnrollment($user);

        return response()->json(['data' => [
            'qr_svg' => $this->twoFactor->qrSvg($user),
            'otpauth_url' => $this->twoFactor->otpauthUrl($user),
            'secret' => $user->two_factor_secret,
            'recovery_codes' => $user->two_factor_recovery_codes,
        ]], 201);
    }

    /** Step 2: confirm with a code from the authenticator app. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        if (! $this->twoFactor->confirm($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }

        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($user->hasTwoFactorEnabled(), 409, 'Two-factor authentication is not enabled.');

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)]]);
    }

    public function destroy(Request $request): Response
    {
        $user = $this->user($request);
        $request->validate(['password' => ['required', 'current_password']]);
        $this->twoFactor->disable($user);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
