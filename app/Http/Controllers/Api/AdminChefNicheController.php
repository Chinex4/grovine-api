<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChefNiche;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminChefNicheController extends Controller
{
    public function index(): JsonResponse
    {
        $niches = ChefNiche::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'is_active', 'sort_order', 'created_at', 'updated_at']);

        return response()->json([
            'message' => 'Chef niches fetched successfully.',
            'data' => $niches,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $niche = ChefNiche::query()->create([
            'name' => $validated['name'],
            'slug' => $this->makeUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Chef niche created successfully.',
            'data' => $niche,
        ], 201);
    }

    public function update(Request $request, ChefNiche $chefNiche): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['slug'] = $this->makeUniqueSlug($validated['name'], $chefNiche->id);
        }

        $chefNiche->update($validated);

        return response()->json([
            'message' => 'Chef niche updated successfully.',
            'data' => $chefNiche->fresh(),
        ]);
    }

    public function destroy(ChefNiche $chefNiche): JsonResponse
    {
        try {
            $chefNiche->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'Cannot delete chef niche currently assigned to users.',
            ], 422);
        }

        return response()->json([
            'message' => 'Chef niche deleted successfully.',
        ]);
    }

    private function makeUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'chef-niche';
        $slug = $base;
        $counter = 2;

        while (
            ChefNiche::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}

