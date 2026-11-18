<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PhoneOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function send(Request $request, PhoneOtpService $otp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->phone_verified_at) {
            return response()->json(['message' => 'Phone number already verified.']);
        }

        $issued = $otp->issue($user);

        return response()->json([
            'message' => 'Verification code sent.',
            'data' => ['expires_at' => $issued->expires_at->toIso8601String()],
        ], 202);
    }

    public function verify(Request $request, PhoneOtpService $otp): UserResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        /** @var User $user */
        $user = $request->user();
        $otp->verify($user, $data['code']);

        return new UserResource($user->fresh());
    }
}
