<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminRecipeController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:DRAFT,PENDING_APPROVAL,APPROVED,REJECTED'],
            'chef_id' => ['nullable', 'uuid', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Recipe::query()
            ->with(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])
            ->withCount(['bookmarkedByUsers', 'ingredients'])
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['chef_id'])) {
            $query->where('chef_id', $validated['chef_id']);
        }

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(function ($inner) use ($term): void {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhere('instructions', 'like', "%{$term}%");
            });
        }

        return response()->json([
            'message' => 'Recipes fetched successfully.',
            'data' => RecipeResource::collection($query->get()),
        ]);
    }

    public function review(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
            'is_recommended' => ['sometimes', 'boolean'],
            'is_quick_recipe' => ['sometimes', 'boolean'],
        ]);

        if ($validated['action'] === 'approve') {
            $recipe->update([
                'status' => Recipe::STATUS_APPROVED,
                'approved_at' => now(),
                'published_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
                'is_recommended' => $validated['is_recommended'] ?? $recipe->is_recommended,
                'is_quick_recipe' => $validated['is_quick_recipe'] ?? $recipe->is_quick_recipe,
            ]);

            $this->notificationService->sendAccountActivity(
                user: $recipe->chef()->firstOrFail(),
                title: 'Recipe approved',
                message: 'Your recipe "'.$recipe->title.'" has been approved and is now visible to users.',
                data: [
                    'recipe_id' => $recipe->id,
                    'status' => $recipe->status,
                ],
                channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
            );
        }

        if ($validated['action'] === 'reject') {
            $recipe->update([
                'status' => Recipe::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $validated['reason'] ?? null,
                'approved_at' => null,
                'published_at' => null,
                'is_recommended' => false,
            ]);

            $this->notificationService->sendAccountActivity(
                user: $recipe->chef()->firstOrFail(),
                title: 'Recipe rejected',
                message: 'Your recipe "'.$recipe->title.'" was rejected by admin.',
                data: [
                    'recipe_id' => $recipe->id,
                    'status' => $recipe->status,
                    'reason' => $recipe->rejection_reason,
                ],
                channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
            );
        }

        return response()->json([
            'message' => 'Recipe review completed successfully.',
            'data' => new RecipeResource($recipe->fresh(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug', 'ingredients.product:id,name,image_url,price,discount,stock,is_active'])),
        ]);
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $video = $recipe->getRawOriginal('video_url');
        $cover = $recipe->getRawOriginal('cover_image_url');

        $recipe->delete();

        $this->deleteStoredFileIfLocal($video);
        $this->deleteStoredFileIfLocal($cover);

        return response()->json([
            'message' => 'Recipe deleted successfully.',
        ]);
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

