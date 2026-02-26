<?php

use App\Http\Controllers\Api\ChefRecipeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', 'role:chef'])->prefix('chef')->group(function (): void {
    Route::get('/recipes', [ChefRecipeController::class, 'index']);
    Route::post('/recipes', [ChefRecipeController::class, 'store']);
    Route::get('/recipes/{recipe}', [ChefRecipeController::class, 'show'])->whereUuid('recipe');
    Route::patch('/recipes/{recipe}', [ChefRecipeController::class, 'update'])->whereUuid('recipe');
    Route::post('/recipes/{recipe}/submit', [ChefRecipeController::class, 'submit'])->whereUuid('recipe');
    Route::delete('/recipes/{recipe}', [ChefRecipeController::class, 'destroy'])->whereUuid('recipe');
});

