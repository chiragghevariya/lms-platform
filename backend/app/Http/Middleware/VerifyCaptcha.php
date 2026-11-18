<?php

namespace App\Http\Middleware;

use App\Security\Captcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCaptcha
{
    public function __construct(private readonly Captcha $captcha) {}

    public function handle(Request $request, Closure $next): Response
    {
        // First-party mobile apps authenticate with tokens and use platform attestation instead.
        $token = $request->input('captcha_token') ?? $request->header('X-Captcha-Token');
        if (! $this->captcha->verify(is_string($token) ? $token : null, $request->ip())) {
            return response()->json([
                'message' => 'Please complete the human check and try again.',
                'code' => 'captcha_failed',
                'errors' => ['captcha_token' => ['Please complete the human check.']],
            ], 422);
        }

        return $next($request);
    }
}
