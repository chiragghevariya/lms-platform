<?php

namespace App\Http\Middleware;

use App\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an admin is viewing as a user, block account-security and money actions so
 * support can look around without changing credentials or charging the user.
 */
class GuardImpersonation
{
    private const BLOCKED = [
        'api/v1/me/password', 'api/v1/me/two-factor*', 'api/v1/me/sessions*', 'api/v1/me/phone/*',
        'api/v1/me/whatsapp', 'api/v1/billing/*', 'api/v1/admin/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && Impersonation::impersonatorId($request) && $request->is(...self::BLOCKED)) {
            return response()->json([
                'message' => 'Not available while viewing as this user. Stop impersonating first.',
                'code' => 'impersonating',
            ], 403);
        }

        return $next($request);
    }
}
