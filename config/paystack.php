<?php

return [
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
    'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
    'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET', env('PAYSTACK_SECRET_KEY', '')),
    'currency' => env('PAYSTACK_CURRENCY', 'NGN'),
    'timeout_seconds' => (int) env('PAYSTACK_TIMEOUT_SECONDS', 15),
];
