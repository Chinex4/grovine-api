<?php

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChefNicheController;
use App\Http\Controllers\Api\ChefProfileController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Controllers\Api\PreferenceCatalogController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RecipeController;
use Illuminate\Support\Facades\Route;

Route::prefix('preferences')->group(function (): void {
    Route::get('/favorite-foods', [PreferenceCatalogController::class, 'favoriteFoods']);
    Route::get('/cuisine-regions', [PreferenceCatalogController::class, 'cuisineRegions']);
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/niches', [ChefNicheController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/search', [ProductController::class, 'search']);
Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/products/recommended', [ProductController::class, 'recommended']);
Route::get('/products/rush-hour-offers', [ProductController::class, 'rushHourOffers']);

Route::get('/recipes', [RecipeController::class, 'index']);
Route::get('/recipes/recommended', [RecipeController::class, 'recommended']);
Route::get('/recipes/quick', [RecipeController::class, 'quick']);
Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->whereUuid('recipe');

Route::get('/ads', [AdController::class, 'index']);
Route::get('/chefs/{username}', [ChefProfileController::class, 'show']);
Route::post('/payments/paystack/webhook', [PaystackWebhookController::class, 'handle']);

