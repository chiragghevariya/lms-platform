<?php

return [
    // Cache store for analytics aggregates. Use "redis" in production; locally the default store.
    'cache_store' => env('ANALYTICS_CACHE_STORE'),
    // How long a computed report stays cached (the nightly job re-warms the common ranges).
    'cache_ttl_minutes' => (int) env('ANALYTICS_CACHE_TTL', 60),
    // Reporting timezone for "today", day buckets and month cohorts.
    'timezone' => env('ANALYTICS_TIMEZONE', 'Asia/Kolkata'),
];
