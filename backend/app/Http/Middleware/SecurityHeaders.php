<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every Laravel response. The JSON API gets a lock-down CSP
 * (it never renders HTML); server-rendered pages get a small same-origin policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self), usb=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->remove('X-Powered-By');
        if (! app()->runningInConsole()) {
            header_remove('X-Powered-By'); // PHP adds it itself when expose_php=On
        }

        // Dev dashboards ship their own inline scripts.
        if (! $request->is('horizon*', 'telescope*')) {
            $h->set('Content-Security-Policy', $request->is('api/*')
                ? "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
                : (string) config('security.csp_web'));
        }

        if ($request->isSecure()) {
            $hsts = 'max-age='.(int) config('security.hsts.max_age');
            if (config('security.hsts.include_subdomains')) {
                $hsts .= '; includeSubDomains';
            }
            if (config('security.hsts.preload')) {
                $hsts .= '; preload';
            }
            $h->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
