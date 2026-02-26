<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/verify-signup-otp', [AuthController::class, 'verifySignupOtp']);

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOtp']);

    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
});

