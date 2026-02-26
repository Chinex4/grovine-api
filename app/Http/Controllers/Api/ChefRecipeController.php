<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChefRecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:DRAFT,PENDING_APPROVAL,APPROVED,REJECTED'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Recipe::query()
            ->where('chef_id', $request->user()->id)
            ->with(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients:id,recipe_id,item_text,product_id,cart_quantity,is_optional,sort_order'])
            ->withCount(['bookmarkedByUsers', 'ingredients'])
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(function ($inner) use ($term): void {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhere('instructions', 'like', "%{$term}%");
            });
        }

        $recipes = $query->get();

        return response()->json([
            'message' => 'Chef recipes fetched successfully.',
            'data' => RecipeResource::collection($recipes),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRecipePayload($request, false);

        $videoPath = $request->hasFile('video')
            ? $request->file('video')->store('recipes/videos', 'public')
            : null;

        $coverPath = $request->hasFile('cover_image')
            ? $request->file('cover_image')->store('recipes/covers', 'public')
            : null;

        $title = $validated['title'] ?? null;

        $recipe = Recipe::query()->create([
            'chef_id' => $request->user()->id,
            'status' => Recipe::STATUS_DRAFT,
            'title' => $title,
            'slug' => $title ? $this->makeUniqueSlug($title) : null,
            'short_description' => $validated['short_description'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'video_url' => $videoPath,
            'cover_image_url' => $coverPath,
            'duration_seconds' => $validated['duration_seconds'] ?? null,
            'servings' => $validated['servings'] ?? null,
            'estimated_cost' => $validated['estimated_cost'] ?? null,
            'is_quick_recipe' => $validated['is_quick_recipe'] ?? false,
        ]);

        if (! empty($validated['ingredients'])) {
            $this->replaceIngredients($recipe, $validated['ingredients']);
        }

        if (! empty($validated['submit']) && $this->isReadyForSubmission($recipe->fresh(['ingredients']))) {
            $recipe->update([
                'status' => Recipe::STATUS_PENDING_APPROVAL,
                'submitted_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
        }

        $recipe->load(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])
            ->loadCount(['bookmarkedByUsers', 'ingredients']);

        return response()->json([
            'message' => 'Recipe saved successfully.',
            'data' => new RecipeResource($recipe),
        ], 201);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->chef_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $recipe->load(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])
            ->loadCount(['bookmarkedByUsers', 'ingredients']);

        return response()->json([
            'message' => 'Recipe fetched successfully.',
            'data' => new RecipeResource($recipe),
        ]);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->chef_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $this->validateRecipePayload($request, true);

        $dirtyPayload = $validated;

        if (array_key_exists('title', $validated)) {
            $dirtyPayload['slug'] = $this->makeUniqueSlug($validated['title'], $recipe->id);
        }

        if ($request->hasFile('video')) {
            $old = $recipe->getRawOriginal('video_url');
            $dirtyPayload['video_url'] = $request->file('video')->store('recipes/videos', 'public');
            $this->deleteStoredFileIfLocal($old);
        }

        if ($request->hasFile('cover_image')) {
            $old = $recipe->getRawOriginal('cover_image_url');
            $dirtyPayload['cover_image_url'] = $request->file('cover_image')->store('recipes/covers', 'public');
            $this->deleteStoredFileIfLocal($old);
        }

        unset($dirtyPayload['ingredients'], $dirtyPayload['submit']);

        $contentUpdated = $dirtyPayload !== [] || array_key_exists('ingredients', $validated);

        if ($dirtyPayload !== []) {
            $recipe->update($dirtyPayload);
        }

        if (array_key_exists('ingredients', $validated)) {
            $this->replaceIngredients($recipe, $validated['ingredients']);
        }

        if ($recipe->status === Recipe::STATUS_APPROVED && $contentUpdated) {
            $recipe->update([
                'status' => Recipe::STATUS_PENDING_APPROVAL,
                'submitted_at' => now(),
                'approved_at' => null,
                'published_at' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'is_recommended' => false,
            ]);
        }

        if (! empty($validated['submit'])) {
            if (! $this->isReadyForSubmission($recipe->fresh(['ingredients']))) {
                return response()->json([
                    'message' => 'Recipe is incomplete. Add video, cover image, title, description, ingredients and full instructions before submitting.',
                ], 422);
            }

            $recipe->update([
                'status' => Recipe::STATUS_PENDING_APPROVAL,
                'submitted_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
        }

        $recipe->load(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])
            ->loadCount(['bookmarkedByUsers', 'ingredients']);

        return response()->json([
            'message' => 'Recipe updated successfully.',
            'data' => new RecipeResource($recipe),
        ]);
    }

    public function submit(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->chef_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $recipe->loadMissing('ingredients');

        if (! $this->isReadyForSubmission($recipe)) {
            return response()->json([
                'message' => 'Recipe is incomplete. Add video, cover image, title, description, ingredients and full instructions before submitting.',
            ], 422);
        }

        $recipe->update([
            'status' => Recipe::STATUS_PENDING_APPROVAL,
            'submitted_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Recipe submitted for admin approval successfully.',
            'data' => new RecipeResource($recipe->fresh(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])),
        ]);
    }

    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->chef_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $video = $recipe->getRawOriginal('video_url');
        $cover = $recipe->getRawOriginal('cover_image_url');

        $recipe->delete();

        $this->deleteStoredFileIfLocal($video);
        $this->deleteStoredFileIfLocal($cover);

        return response()->json([
            'message' => 'Recipe deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRecipePayload(Request $request, bool $forUpdate): array
    {
        return $request->validate([
            'title' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:180'],
            'short_description' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:2000'],
            'instructions' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'string'],
            'video' => [$forUpdate ? 'sometimes' : 'nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska', 'max:102400'],
            'cover_image' => [$forUpdate ? 'sometimes' : 'nullable', 'image', 'max:5120'],
            'duration_seconds' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'integer', 'min:1'],
            'servings' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'integer', 'min:1'],
            'estimated_cost' => [$forUpdate ? 'sometimes' : 'nullable', 'nullable', 'numeric', 'min:0'],
            'is_quick_recipe' => [$forUpdate ? 'sometimes' : 'nullable', 'boolean'],
            'ingredients' => [$forUpdate ? 'sometimes' : 'nullable', 'array', 'min:1'],
            'ingredients.*.item_text' => ['required_with:ingredients', 'string', 'max:255'],
            'ingredients.*.product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'ingredients.*.cart_quantity' => ['nullable', 'integer', 'min:1'],
            'ingredients.*.is_optional' => ['nullable', 'boolean'],
            'submit' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $ingredients
     */
    private function replaceIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();

        foreach ($ingredients as $index => $ingredient) {
            $recipe->ingredients()->create([
                'item_text' => $ingredient['item_text'],
                'product_id' => $ingredient['product_id'] ?? null,
                'cart_quantity' => (int) ($ingredient['cart_quantity'] ?? 1),
                'is_optional' => (bool) ($ingredient['is_optional'] ?? false),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function isReadyForSubmission(Recipe $recipe): bool
    {
        $recipe->loadMissing('ingredients');

        return filled($recipe->title)
            && filled($recipe->short_description)
            && filled($recipe->instructions)
            && filled($recipe->getRawOriginal('video_url'))
            && filled($recipe->getRawOriginal('cover_image_url'))
            && $recipe->ingredients->count() > 0;
    }

    private function makeUniqueSlug(string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'recipe';
        $slug = $base;
        $counter = 2;

        while (
            Recipe::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function deleteStoredFileIfLocal(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}

