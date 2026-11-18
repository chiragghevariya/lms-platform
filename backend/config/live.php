<?php

return [
    // "Join now" opens this many minutes before the start.
    'join_early_minutes' => 10,

    // Reminder offsets (minutes before start). Delivery goes through the LiveSessionReminderDue event (Phase 8 adds channels).
    'reminders' => [24 * 60, 15],

    // Mark a session ended this long after its scheduled end when no "meeting ended" webhook arrived.
    'auto_end_grace_minutes' => 30,

    'zoom' => [
        // Server-to-Server OAuth app (Zoom Marketplace → Develop → Server-to-Server OAuth).
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        // Host user for created meetings ("me" = the account owner, or a licensed user's email/id).
        'host_user' => env('ZOOM_HOST_USER', 'me'),
        // Meeting SDK credentials (General app with Meeting SDK enabled, same Zoom account as above).
        'sdk_key' => env('ZOOM_SDK_KEY'),
        'sdk_secret' => env('ZOOM_SDK_SECRET'),
        // Event subscriptions "Secret Token" (webhook signature + URL validation).
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
        'api_base' => 'https://api.zoom.us/v2',
        'oauth_url' => 'https://zoom.us/oauth/token',
        'auto_recording' => env('ZOOM_AUTO_RECORDING', 'cloud'), // cloud | local | none
    ],
];
