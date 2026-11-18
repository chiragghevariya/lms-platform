<?php

return [
    'brand' => [
        'name' => env('APP_NAME', 'Brand'),
        'tagline' => env('APP_TAGLINE', 'Learn Skills. Build Income.'),
    ],

    // SPA origin used to build links inside emails (verify email, reset password).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5180'), '/'),

    // Default ISO-4217 currency for prices (money stored in minor units).
    'currency' => env('APP_CURRENCY', 'INR'),

    'security' => [
        'max_login_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        'two_factor_challenge_minutes' => 5,
        'two_factor_max_attempts' => 5,
    ],

    'otp' => [
        'driver' => env('OTP_DRIVER', 'log'),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts' => 5,
        'length' => 6,
    ],

    'avatar_disk' => env('AVATAR_DISK', 'public'),

    // Used by SuperAdminSeeder (password required in production).
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    'contact_email' => env('CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),

    'verification_expire_minutes' => (int) env('VERIFICATION_EXPIRE_MINUTES', 60),
];
