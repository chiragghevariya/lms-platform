<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureDeviceActive;
use App\Http\Middleware\EnsureMembershipTier;
use App\Http\Middleware\GuardImpersonation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyCaptcha;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth for first-party origins (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();
        // Behind a load balancer / CloudFront: trust its X-Forwarded-* headers (client IP, https).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'), headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB);
        // Referral cookies are HMAC-signed by App\Affiliates\ReferralCookie (same format for SPA and API requests).
        $middleware->encryptCookies(except: ['lms_ref', 'lms_invite']);
        $middleware->throttleApi(); // 'api' limiter: 120/min per user or IP
        $middleware->appendToGroup('api', GuardImpersonation::class);
        $middleware->appendToGroup('api', EnsureDeviceActive::class);
        $middleware->append(SecurityHeaders::class);

        // API never redirects guests; the SPA owns the /login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*')
            ? null
            : config('lms.frontend_url').'/login');

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'tier' => EnsureMembershipTier::class,
            'active' => EnsureAccountIsActive::class,
            'captcha' => VerifyCaptcha::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'This action is unauthorized.'], 403)
            : null);
    })->create();
