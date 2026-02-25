<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY', 'grovine-test-jwt-secret')),
    'ttl_days' => (int) env('JWT_TTL_DAYS', 2),
    'issuer' => env('APP_URL', 'grovine-api'),
];
