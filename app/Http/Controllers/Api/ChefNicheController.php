<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChefNiche;
use Illuminate\Http\JsonResponse;

class ChefNicheController extends Controller
{
    public function index(): JsonResponse
    {
        $niches = ChefNiche::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'sort_order']);

        return response()->json([
            'message' => 'Chef niches fetched successfully.',
            'data' => $niches,
        ]);
    }
}

