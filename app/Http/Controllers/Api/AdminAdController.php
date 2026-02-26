<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminAdController extends Controller
{
    public function index(): JsonResponse
    {
        $ads = Ad::query()
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'image_url', 'link', 'is_active', 'sort_order', 'created_at', 'updated_at']);

        return response()->json([
            'message' => 'Ads fetched successfully.',
            'data' => $ads,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'image' => ['required', 'image', 'max:5120'],
            'link' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $path = $request->file('image')->store('ads', 'public');

        $ad = Ad::query()->create([
            'title' => $validated['title'],
            'image_url' => $path,
            'link' => $validated['link'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Ad created successfully.',
            'data' => $ad,
        ], 201);
    }

    public function update(Request $request, Ad $ad): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'image' => ['sometimes', 'image', 'max:5120'],
            'link' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($request->hasFile('image')) {
            $oldPath = $ad->getRawOriginal('image_url');

            if ($oldPath && ! str_starts_with($oldPath, 'http://') && ! str_starts_with($oldPath, 'https://')) {
                Storage::disk('public')->delete($oldPath);
            }

            $validated['image_url'] = $request->file('image')->store('ads', 'public');
        }

        unset($validated['image']);

        $ad->update($validated);

        return response()->json([
            'message' => 'Ad updated successfully.',
            'data' => $ad->fresh(),
        ]);
    }

    public function destroy(Ad $ad): JsonResponse
    {
        $path = $ad->getRawOriginal('image_url');

        if ($path && ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://')) {
            Storage::disk('public')->delete($path);
        }

        $ad->delete();

        return response()->json([
            'message' => 'Ad deleted successfully.',
        ]);
    }
}
