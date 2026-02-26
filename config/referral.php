<?php

return [
    'currency' => env('REFERRAL_CURRENCY', 'NGN'),
    'referrer_first_order_reward' => (float) env('REFERRAL_REWARD_REFERRER_FIRST_ORDER', 500),
    'referrer_second_order_reward' => (float) env('REFERRAL_REWARD_REFERRER_SECOND_ORDER', 500),
    'referred_first_order_reward' => (float) env('REFERRAL_REWARD_REFERRED_FIRST_ORDER', 500),
];

