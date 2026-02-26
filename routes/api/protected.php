<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ChefProfileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->group(function (): void {
    Route::post('/onboarding/preferences', [OnboardingController::class, 'setPreferences']);
    Route::get('/user/me', [UserProfileController::class, 'me']);
    Route::patch('/user/me', [UserProfileController::class, 'update']);
    Route::post('/user/profile-picture', [UserProfileController::class, 'uploadProfilePicture']);
    Route::delete('/user/me', [UserProfileController::class, 'destroy']);
    Route::post('/chef/become', [ChefProfileController::class, 'become']);

    Route::get('/recipes/bookmarks', [RecipeController::class, 'bookmarks']);
    Route::post('/recipes/{recipe}/bookmark', [RecipeController::class, 'bookmark'])->whereUuid('recipe');
    Route::delete('/recipes/{recipe}/bookmark', [RecipeController::class, 'unbookmark'])->whereUuid('recipe');
    Route::post('/recipes/{recipe}/ingredients/add-to-cart', [RecipeController::class, 'addIngredientsToCart'])->whereUuid('recipe');

    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::patch('/cart/items/{cartItem}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'removeItem']);
    Route::delete('/cart', [CartController::class, 'clear']);

    Route::post('/checkout', [CheckoutController::class, 'checkout']);
    Route::post('/payments/paystack/verify', [CheckoutController::class, 'verifyPaystackPayment']);

    Route::post('/wallet/deposits/initialize', [WalletController::class, 'initializeDeposit']);
    Route::post('/wallet/deposits/verify', [WalletController::class, 'verifyDeposit']);
    Route::get('/wallet/banks/nigeria', [WalletController::class, 'banks']);
    Route::post('/wallet/verify-account', [WalletController::class, 'verifyAccount']);
    Route::post('/wallet/withdrawals', [WalletController::class, 'withdraw']);
    Route::get('/wallet/transactions', [WalletController::class, 'history']);
    Route::get('/wallet/balance', [WalletController::class, 'balance']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::get('/referrals', [ReferralController::class, 'index']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/device-tokens', [NotificationController::class, 'registerDeviceToken']);
    Route::delete('/notifications/device-tokens/{deviceToken}', [NotificationController::class, 'removeDeviceToken']);
});

