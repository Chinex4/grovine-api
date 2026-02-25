<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function setPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'favorite_food_ids' => ['required', 'array', 'min:1'],
            'favorite_food_ids.*' => ['uuid', 'exists:favorite_foods,id'],
            'cuisine_region_ids' => ['required', 'array', 'min:1'],
            'cuisine_region_ids.*' => ['uuid', 'exists:cuisine_regions,id'],
        ]);

        $user = $request->user();
        $now = now();

        $foodSyncPayload = collect($validated['favorite_food_ids'])
            ->unique()
            ->values()
            ->mapWithKeys(fn (string $id) => [
                $id => [
                    'id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ])
            ->all();

        $regionSyncPayload = collect($validated['cuisine_region_ids'])
            ->unique()
            ->values()
            ->mapWithKeys(fn (string $id) => [
                $id => [
                    'id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ])
            ->all();

        $user->favoriteFoods()->sync($foodSyncPayload);
        $user->cuisineRegions()->sync($regionSyncPayload);

        $user->forceFill([
            'onboarding_completed' => true,
        ])->save();

        $user->load(['favoriteFoods:id,name,slug', 'cuisineRegions:id,name,slug']);

        return response()->json([
            'message' => 'Preferences saved successfully.',
            'data' => [
                'onboarding_completed' => $user->onboarding_completed,
                'favorite_foods' => $user->favoriteFoods,
                'cuisine_regions' => $user->cuisineRegions,
            ],
        ]);
    }
}
