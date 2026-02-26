<?php

return [
    'delivery_fee' => (float) env('CHECKOUT_DELIVERY_FEE', 0),
    'service_fee' => (float) env('CHECKOUT_SERVICE_FEE', 0),
    'affiliate_fee' => (float) env('CHECKOUT_AFFILIATE_FEE', 0),
];
