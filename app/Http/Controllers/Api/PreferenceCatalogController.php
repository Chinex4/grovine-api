<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuisineRegion;
use App\Models\FavoriteFood;
use Illuminate\Http\JsonResponse;

class PreferenceCatalogController extends Controller
{
    public function favoriteFoods(): JsonResponse
    {
        $foods = FavoriteFood::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'message' => 'Favorite foods fetched successfully.',
            'data' => $foods,
        ]);
    }

    public function cuisineRegions(): JsonResponse
    {
        $regions = CuisineRegion::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'message' => 'Cuisine regions fetched successfully.',
            'data' => $regions,
        ]);
    }
}
