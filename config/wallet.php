<?php

return [
    'min_deposit' => (float) env('WALLET_MIN_DEPOSIT', 100),
    'min_withdrawal' => (float) env('WALLET_MIN_WITHDRAWAL', 1000),
    'default_currency' => env('WALLET_CURRENCY', 'NGN'),
];
