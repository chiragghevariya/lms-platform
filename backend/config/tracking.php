<?php

// Optional marketing analytics (free). Browser: GA4 gtag + Meta Pixel (public IDs, loaded by
// the SPA only when set). Server: GA4 Measurement Protocol + Meta Conversions API for
// purchases, sent from the queue when the secrets are set. Editable in Admin → Settings.
return [
    'ga4' => [
        'measurement_id' => env('GA4_MEASUREMENT_ID'),
        'api_secret' => env('GA4_API_SECRET'),
    ],
    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'access_token' => env('META_CAPI_TOKEN'),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
    ],
];
