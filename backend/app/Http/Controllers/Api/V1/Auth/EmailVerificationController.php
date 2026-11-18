<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }

    /** Route is protected by `signed:relative`; the link carries the user's public uuid. */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            hash_equals($user->uuid, $id) && hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
            'Invalid verification link.',
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['data' => new UserResource($user)]);
    }
}
