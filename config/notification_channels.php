<?php

return [
    'firebase' => [
        'endpoint' => env('FIREBASE_ENDPOINT', 'https://fcm.googleapis.com/fcm/send'),
        'server_key' => env('FIREBASE_SERVER_KEY', ''),
        'timeout_seconds' => (int) env('FIREBASE_TIMEOUT_SECONDS', 15),
    ],
    'apn' => [
        'environment' => env('APN_ENVIRONMENT', 'development'),
        'key_id' => env('APN_KEY_ID', ''),
        'team_id' => env('APN_TEAM_ID', ''),
        'bundle_id' => env('APN_BUNDLE_ID', ''),
        'private_key' => env('APN_PRIVATE_KEY', ''),
        'timeout_seconds' => (int) env('APN_TIMEOUT_SECONDS', 15),
    ],
    'email' => [
        'enabled' => filter_var(env('NOTIFICATIONS_EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
];
