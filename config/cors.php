<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Limit browser access to known Grovine frontends and local dev hosts.
    | Browsers send a preflight OPTIONS request before some cross-origin API
    | calls, so the API must explicitly allow those origins on /api/* routes.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://grovine.ng,https://console.grovine.ng,http://localhost:3000,http://localhost:5173,http://127.0.0.1:3000,http://127.0.0.1:5173'
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', true), FILTER_VALIDATE_BOOL),

];
