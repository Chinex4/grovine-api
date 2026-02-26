<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Recipe
 */
class RecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chef_id' => $this->chef_id,
            'status' => $this->status,
            'title' => $this->title,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'instructions' => $this->when($request->query('include_instructions') === '1' || $this->relationLoaded('ingredients'), $this->instructions),
            'video_url' => $this->video_url,
            'cover_image_url' => $this->cover_image_url,
            'duration_seconds' => $this->duration_seconds,
            'servings' => $this->servings,
            'estimated_cost' => $this->estimated_cost,
            'is_recommended' => $this->is_recommended,
            'is_quick_recipe' => $this->is_quick_recipe,
            'views_count' => $this->views_count,
            'bookmarks_count' => $this->whenCounted('bookmarkedByUsers'),
            'ingredients_count' => $this->whenCounted('ingredients'),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'published_at' => $this->published_at?->toIso8601String(),
            'chef' => $this->whenLoaded('chef', function (): ?array {
                if (! $this->chef) {
                    return null;
                }

                return [
                    'id' => $this->chef->id,
                    'name' => $this->chef->name,
                    'chef_name' => $this->chef->chef_name,
                    'username' => $this->chef->username,
                    'profile_picture' => $this->chef->profile_picture,
                    'chef_profile_share_url' => url('/api/chefs/'.$this->chef->username),
                    'niche' => $this->chef->chefNiche
                        ? [
                            'id' => $this->chef->chefNiche->id,
                            'name' => $this->chef->chefNiche->name,
                            'slug' => $this->chef->chefNiche->slug,
                        ]
                        : null,
                ];
            }),
            'ingredients' => RecipeIngredientResource::collection($this->whenLoaded('ingredients')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

