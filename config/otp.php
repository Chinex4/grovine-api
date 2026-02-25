<?php

return [
    'length' => (int) env('OTP_LENGTH', 5),
    'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 10),
    'resend_throttle_seconds' => (int) env('OTP_RESEND_THROTTLE_SECONDS', 60),
    'debug_expose_code' => (bool) env('OTP_DEBUG_EXPOSE_CODE', false),
];
