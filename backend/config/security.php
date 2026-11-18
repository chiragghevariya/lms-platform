<?php

return [
    // HSTS is only sent over HTTPS. Preload only once every subdomain is HTTPS-only.
    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],

    // Server-rendered pages (unsubscribe, invoice HTML) are tiny; the SPA's CSP lives in
    // deploy/nginx (it's served as static files, not by Laravel).
    'csp_web' => env('CSP_WEB', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'"),

    'rate_limits' => [
        'api' => (int) env('API_RATE_LIMIT', 120),     // per minute
        'admin' => (int) env('ADMIN_RATE_LIMIT', 300), // per minute
    ],

    // Bot protection on register/login/contact/forgot-password. Driver: turnstile | none.
    'captcha' => [
        'driver' => env('CAPTCHA_DRIVER', 'none'),
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET'),
        ],
    ],

    // Account sharing: learners may be signed in on this many devices at once (staff unlimited).
    'max_devices' => (int) env('MAX_DEVICES', 2),

    // Malware scan for uploads. Driver: none | clamav (clamd over a socket).
    'upload_scan' => [
        'driver' => env('UPLOAD_SCAN_DRIVER', 'none'),
        'clamav_socket' => env('CLAMAV_SOCKET', '/opt/homebrew/var/run/clamav/clamd.sock'),
        'fail_closed' => (bool) env('UPLOAD_SCAN_FAIL_CLOSED', true), // reject uploads if the scanner is down
    ],
];
