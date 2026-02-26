<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referralService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $summary = $this->referralService->summary($request->user());

        return response()->json([
            'message' => 'Referral details fetched successfully.',
            'data' => $summary,
        ]);
    }
}

