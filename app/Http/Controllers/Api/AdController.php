<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;

class AdController extends Controller
{
    public function index(): JsonResponse
    {
        $ads = Ad::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'image_url', 'link', 'sort_order']);

        return response()->json([
            'message' => 'Ads fetched successfully.',
            'data' => $ads,
        ]);
    }
}
