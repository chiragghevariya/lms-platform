<?php

namespace App\Http\Middleware;

use App\Admin\Impersonation;
use App\Models\User;
use App\Security\DeviceLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser sessions carry a device id. If that device was pushed out by a newer sign-in
 * (or revoked from Security settings), the session ends here with a clear error code.
 * Sessions from before this feature get a device on first use.
 */
class EnsureDeviceActive
{
    public function __construct(private readonly DeviceLimiter $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession() || $request->bearerToken() || Impersonation::impersonatorId($request)) {
            return $next($request);
        }

        $id = $request->session()->get(DeviceLimiter::SESSION_KEY);
        if (! is_string($id)) {
            if (Auth::guard('web')->check()) {
                $this->devices->registerSession($request, $user);
            }

            return $next($request);
        }

        if (! $this->devices->sessionActive($id)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'You were signed out because your account was used on another device. Your plan allows '.config('security.max_devices').' devices at a time.',
                'code' => 'device_signed_out',
            ], 401);
        }

        $this->devices->touch($id);

        return $next($request);
    }
}
