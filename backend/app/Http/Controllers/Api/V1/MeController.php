<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoginEvent;
use App\Enums\MembershipTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\LoginActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends Controller
{
    /** Boot probe for the SPA: 200 with the user or null (guests don't trigger a 401 in the console). */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        if (! $user instanceof User || ! $user->status->canSignIn()) {
            return response()->json(['data' => null])->header('Cache-Control', 'no-store');
        }
        $request->setUserResolver(fn () => $user);

        return (new UserResource($user))->response()->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $profile = $data['profile'] ?? null;
        unset($data['profile']);

        DB::transaction(function () use ($user, $data, $profile) {
            $user->fill($data);
            if ($user->isDirty('phone')) {
                $user->phone_verified_at = null; // a new number must be re-verified
            }
            $user->save();

            if (is_array($profile)) {
                $user->profileOrNew()->fill($profile)->save();
            }
        });

        return new UserResource($user->fresh());
    }

    public function updatePassword(UpdatePasswordRequest $request, AuthSessionService $sessions, LoginActivityRecorder $activity): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['password' => $request->string('password')->value()]);
        $activity->record(LoginEvent::PasswordChanged, $user);

        // Revoke every other mobile token; keep the one making this request (if any).
        $current = $sessions->bearerToken($request);
        PersonalAccessToken::query()
            ->whereMorphedTo('tokenable', $user)
            ->when($current, fn ($q, PersonalAccessToken $t) => $q->whereKeyNot($t->getKey()))
            ->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    /** Lightweight check the clients can use for paywalls: GET /me/access?tier=pro */
    public function access(Request $request): JsonResponse
    {
        $validated = $request->validate(['tier' => ['required', 'string', Rule::in([...MembershipTier::slugs(), '1', '2', '3'])]]);
        $required = MembershipTier::parse($validated['tier']);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'required_tier' => $required->slug(),
            'required_tier_level' => $required->value,
            'current_tier' => $user->currentTier()?->slug(),
            'has_access' => $user->canAccessTier($required),
        ]]);
    }
}
