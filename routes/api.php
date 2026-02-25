<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PreferenceCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/verify-signup-otp', [AuthController::class, 'verifySignupOtp']);

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOtp']);

    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
});

Route::prefix('preferences')->group(function (): void {
    Route::get('/favorite-foods', [PreferenceCatalogController::class, 'favoriteFoods']);
    Route::get('/cuisine-regions', [PreferenceCatalogController::class, 'cuisineRegions']);
});

Route::middleware('auth.jwt')->group(function (): void {
    Route::post('/onboarding/preferences', [OnboardingController::class, 'setPreferences']);
});
