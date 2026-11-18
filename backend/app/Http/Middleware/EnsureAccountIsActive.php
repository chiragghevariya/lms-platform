<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthFlowException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Kicks out suspended/banned users who still hold a session or token. */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user && ! $user->status->canSignIn()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            throw AuthFlowException::blocked();
        }

        return $next($request);
    }
}
