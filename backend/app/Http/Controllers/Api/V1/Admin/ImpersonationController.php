<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Admin\Impersonation;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Support "view as user": swaps the web-session user and remembers the admin in the
 * session. Only for the browser admin panel (cookie session), never staff accounts,
 * and every start/stop is audited.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if (! $request->hasSession() || $request->bearerToken()) {
            throw ValidationException::withMessages(['user' => 'Impersonation is only available from the web admin panel.']);
        }
        if (Impersonation::impersonatorId($request)) {
            throw ValidationException::withMessages(['user' => 'You are already viewing as another user.']);
        }
        if ($admin->is($user) || $user->isStaff() || ! $user->status->canSignIn()) {
            throw ValidationException::withMessages(['user' => 'Only active, non-staff accounts can be impersonated.']);
        }

        activity('admin')->causedBy($admin)->performedOn($user)->event('impersonation.started')
            ->withProperties(['ip' => $request->ip()])->log('Started viewing as user');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::KEY, $admin->id);
        $request->setUserResolver(fn () => $user); // so the payload below describes the new session user

        return response()->json(['data' => (new UserResource($user->load('roles')))->resolve($request)]);
    }

    public function stop(Request $request): JsonResponse
    {
        $admin = Impersonation::impersonator($request);
        if (! $admin) {
            throw ValidationException::withMessages(['user' => 'You are not impersonating anyone.']);
        }
        /** @var User $user */
        $user = $request->user();

        $request->session()->forget(Impersonation::KEY);
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->setUserResolver(fn () => $admin);

        activity('admin')->causedBy($admin)->performedOn($user)->event('impersonation.stopped')->log('Stopped viewing as user');

        return response()->json(['data' => (new UserResource($admin->load('roles')))->resolve($request)]);
    }
}
