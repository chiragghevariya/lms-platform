<?php

return [
    // Currency shown to guests / users without a country. INR users pay via Razorpay, others via Stripe.
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'INR'),
    'supported_currencies' => ['INR', 'USD'],

    // Countries routed to Razorpay regardless of currency.
    'razorpay_countries' => ['IN'],

    // Dunning: keep access this many days after a failed renewal before downgrading.
    // Load testing only (ignored in production): fake gateway with artificial latency.
    'sandbox_gateway' => (bool) env('BILLING_SANDBOX_GATEWAY', false),
    'sandbox_latency_ms' => (int) env('BILLING_SANDBOX_LATENCY_MS', 150),

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    // Razorpay can't "undo" a scheduled cancellation, so we only send it this many hours
    // before renewal; until then the user can resume.
    'razorpay_cancel_lead_hours' => 24,

    'tax' => [
        // 'inclusive': listed prices include tax (typical for India GST). 'exclusive': tax added on top.
        'mode' => env('BILLING_TAX_MODE', 'inclusive'),
        // Country => [name, rate %]. Unlisted countries: no tax line.
        'rates' => [
            'IN' => ['name' => 'GST', 'rate' => 18.0],
        ],
    ],

    'seller' => [
        'name' => env('BILLING_SELLER_NAME', env('APP_NAME', 'Brand')),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'tax_id' => env('BILLING_SELLER_TAX_ID', ''), // GSTIN / VAT number
        'email' => env('BILLING_SELLER_EMAIL', env('MAIL_FROM_ADDRESS')),
    ],

    'invoices' => [
        'disk' => env('INVOICE_DISK', 'local'), // private disk; served through an authenticated endpoint
        'prefix' => env('INVOICE_PREFIX', 'INV'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'base_url' => 'https://api.razorpay.com/v1',
        // Billing cycles Razorpay should run before auto-completing (large = effectively forever).
        'total_count' => ['month' => 120, 'year' => 10],
    ],

    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => 300,
    ],
];
