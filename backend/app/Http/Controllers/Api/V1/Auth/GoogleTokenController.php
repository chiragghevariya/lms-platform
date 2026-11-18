<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\BeginSession;
use App\Actions\Auth\ResolveGoogleUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/** Mobile: exchange a Google access token (from the native Google Sign-In SDK) for an API token. */
class GoogleTokenController extends Controller
{
    public function __invoke(Request $request, ResolveGoogleUser $resolve, BeginSession $begin): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        // The token must have been issued to one of OUR client ids; otherwise any Google app
        // that a victim signed into could replay its token here (account takeover).
        $allowed = array_filter([config('services.google.client_id'), ...(array) config('services.google.mobile_client_ids')]);
        try {
            $info = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $data['access_token']]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['access_token' => 'Google sign-in failed. Please try again.']);
        }
        $audience = [(string) $info->json('aud'), (string) $info->json('azp')];
        if (! $info->ok() || $allowed === [] || array_intersect($audience, $allowed) === []) {
            throw ValidationException::withMessages(['access_token' => 'This Google sign-in was not issued for this app.']);
        }

        try {
            /** @var AbstractProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->userFromToken($data['access_token']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['access_token' => 'Google sign-in failed. Please try again.']);
        }

        $user = $resolve->handle($googleUser);

        return response()->json(['data' => $begin->handle($request, $user, false, $data['device_name'], 'google')]);
    }
}
