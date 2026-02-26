<?php

use App\Http\Controllers\Api\AdminAdController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminChefNicheController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminRecipeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin')->group(function (): void {
    Route::get('/ads', [AdminAdController::class, 'index']);
    Route::post('/ads', [AdminAdController::class, 'store']);
    Route::patch('/ads/{ad}', [AdminAdController::class, 'update']);
    Route::delete('/ads/{ad}', [AdminAdController::class, 'destroy']);

    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

    Route::get('/products', [AdminProductController::class, 'index']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::patch('/products/{product}', [AdminProductController::class, 'update']);
    Route::delete('/products/{product}', [AdminProductController::class, 'destroy']);

    Route::post('/notifications/send', [AdminNotificationController::class, 'send']);

    Route::get('/niches', [AdminChefNicheController::class, 'index']);
    Route::post('/niches', [AdminChefNicheController::class, 'store']);
    Route::patch('/niches/{chefNiche}', [AdminChefNicheController::class, 'update']);
    Route::delete('/niches/{chefNiche}', [AdminChefNicheController::class, 'destroy']);

    Route::get('/recipes', [AdminRecipeController::class, 'index']);
    Route::patch('/recipes/{recipe}/review', [AdminRecipeController::class, 'review'])->whereUuid('recipe');
    Route::delete('/recipes/{recipe}', [AdminRecipeController::class, 'destroy'])->whereUuid('recipe');
});

