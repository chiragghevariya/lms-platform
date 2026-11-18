<?php

return [
    /*
     * bunny | demo
     * demo: no external service. Uploads are accepted and discarded; playback uses a public
     * test HLS stream. For local development and UI work only — never in production.
     */
    'driver' => env('VIDEO_DRIVER', 'demo'),

    // Lifetime of signed playback URLs: long enough to finish the video (segments are
    // fetched throughout playback), short enough that a leaked link soon dies. The player
    // re-requests a fresh URL on expiry.
    'playback_ttl' => [
        'min_seconds' => (int) env('VIDEO_PLAYBACK_TTL_MIN', 900),       // 15 min
        'max_seconds' => (int) env('VIDEO_PLAYBACK_TTL_MAX', 4 * 3600),  // 4 h
        'buffer_seconds' => 900,
    ],

    'upload_ttl_seconds' => 6 * 3600,

    'bunny' => [
        'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        'api_key' => env('BUNNY_STREAM_API_KEY'),                 // library API key (server only)
        'read_only_api_key' => env('BUNNY_STREAM_READ_ONLY_KEY'), // signs Stream webhooks
        'cdn_hostname' => env('BUNNY_STREAM_CDN_HOSTNAME'),       // e.g. vz-xxxx.b-cdn.net
        'token_security_key' => env('BUNNY_STREAM_TOKEN_KEY'),    // pull zone token auth key
        'api_base' => 'https://video.bunnycdn.com',
        'tus_endpoint' => 'https://video.bunnycdn.com/tusupload',
    ],

    'demo' => [
        'hls_url' => env('VIDEO_DEMO_HLS_URL', 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8'),
        'duration_seconds' => 634,
    ],

    'resources_disk' => env('COURSE_FILES_DISK', 'local'),
    'thumbnails_disk' => env('COURSE_THUMBNAILS_DISK', 'public'),
];
