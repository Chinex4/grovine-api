<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutService $checkoutService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'chef_username' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $query = $this->basePublicQuery();

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhereHas('chef', function (Builder $chefQuery) use ($term): void {
                        $chefQuery->where('name', 'like', "%{$term}%")
                            ->orWhere('chef_name', 'like', "%{$term}%")
                            ->orWhere('username', 'like', "%{$term}%");
                    });
            });
        }

        if (! empty($validated['chef_username'])) {
            $query->whereHas('chef', function (Builder $chefQuery) use ($validated): void {
                $chefQuery->where('username', strtolower((string) $validated['chef_username']));
            });
        }

        $recipes = $query
            ->orderByDesc('is_recommended')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'message' => 'Recipes fetched successfully.',
            'data' => RecipeResource::collection($recipes),
        ]);
    }

    public function recommended(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 20);

        $recipes = $this->basePublicQuery()
            ->orderByDesc('is_recommended')
            ->orderByDesc('bookmarked_by_users_count')
            ->orderByDesc('views_count')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'message' => 'Recommended recipes fetched successfully.',
            'data' => RecipeResource::collection($recipes),
        ]);
    }

    public function quick(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 20);

        $recipes = $this->basePublicQuery()
            ->where(function (Builder $query): void {
                $query->where('is_quick_recipe', true)
                    ->orWhere(function (Builder $inner): void {
                        $inner->whereNotNull('duration_seconds')
                            ->where('duration_seconds', '<=', 600);
                    });
            })
            ->orderByDesc('is_quick_recipe')
            ->orderBy('duration_seconds')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'message' => 'Quick recipes fetched successfully.',
            'data' => RecipeResource::collection($recipes),
        ]);
    }

    public function show(Recipe $recipe): JsonResponse
    {
        if ($recipe->status !== Recipe::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Recipe not found.',
            ], 404);
        }

        $recipe->increment('views_count');
        $recipe->refresh();
        $recipe->load([
            'chef:id,name,chef_name,username,profile_picture,chef_niche_id',
            'chef.chefNiche:id,name,slug',
            'ingredients.product:id,name,image_url,price,discount,stock,is_active',
        ])->loadCount(['bookmarkedByUsers', 'ingredients']);

        $related = $this->relatedRecipes($recipe);

        return response()->json([
            'message' => 'Recipe fetched successfully.',
            'data' => [
                'recipe' => new RecipeResource($recipe),
                'related_recipes' => RecipeResource::collection($related),
            ],
        ]);
    }

    public function bookmark(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->status !== Recipe::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Only approved recipes can be bookmarked.',
            ], 422);
        }

        $request->user()->bookmarkedRecipes()->syncWithoutDetaching([$recipe->id]);

        return response()->json([
            'message' => 'Recipe bookmarked successfully.',
        ]);
    }

    public function unbookmark(Request $request, Recipe $recipe): JsonResponse
    {
        $request->user()->bookmarkedRecipes()->detach($recipe->id);

        return response()->json([
            'message' => 'Recipe bookmark removed successfully.',
        ]);
    }

    public function bookmarks(Request $request): JsonResponse
    {
        $recipes = $request->user()
            ->bookmarkedRecipes()
            ->where('status', Recipe::STATUS_APPROVED)
            ->with(['chef:id,name,chef_name,username,profile_picture,chef_niche_id', 'chef.chefNiche:id,name,slug'])
            ->withCount(['bookmarkedByUsers', 'ingredients'])
            ->orderByDesc('recipe_bookmarks.created_at')
            ->get();

        return response()->json([
            'message' => 'Bookmarked recipes fetched successfully.',
            'data' => RecipeResource::collection($recipes),
        ]);
    }

    public function addIngredientsToCart(Request $request, Recipe $recipe): JsonResponse
    {
        $user = $request->user();

        if (! $this->canViewForIngredientCart($recipe, $user)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'ingredient_ids' => ['required', 'array', 'min:1'],
            'ingredient_ids.*' => ['uuid', 'exists:recipe_ingredients,id'],
            'quantity_multiplier' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $multiplier = (int) ($validated['quantity_multiplier'] ?? 1);

        $ingredients = RecipeIngredient::query()
            ->where('recipe_id', $recipe->id)
            ->whereIn('id', $validated['ingredient_ids'])
            ->with('product:id,name,price,discount,stock,is_active')
            ->get();

        if ($ingredients->count() !== count($validated['ingredient_ids'])) {
            return response()->json([
                'message' => 'Some selected ingredients are invalid for this recipe.',
            ], 422);
        }

        $usable = $ingredients->filter(static fn (RecipeIngredient $ingredient): bool => filled($ingredient->product_id));

        if ($usable->isEmpty()) {
            return response()->json([
                'message' => 'Selected ingredients are not linked to store products.',
            ], 422);
        }

        $demandByProduct = [];

        foreach ($usable as $ingredient) {
            $product = $ingredient->product;

            if (! $product || ! $product->is_active) {
                return response()->json([
                    'message' => 'One or more selected products are currently unavailable.',
                ], 422);
            }

            $needed = max((int) $ingredient->cart_quantity * $multiplier, 1);
            $demandByProduct[$product->id] = ($demandByProduct[$product->id] ?? 0) + $needed;
        }

        foreach ($demandByProduct as $productId => $qtyNeeded) {
            $product = $usable->firstWhere('product_id', $productId)?->product;
            $inCart = (int) $user->cartItems()->where('product_id', $productId)->value('quantity');

            if (! $product || ($inCart + $qtyNeeded) > (int) $product->stock) {
                return response()->json([
                    'message' => 'Requested ingredient quantity exceeds available stock for one or more products.',
                ], 422);
            }
        }

        foreach ($demandByProduct as $productId => $qtyNeeded) {
            $product = $usable->firstWhere('product_id', $productId)?->product;

            if (! $product) {
                continue;
            }

            $current = (int) $user->cartItems()->where('product_id', $productId)->value('quantity');
            $this->checkoutService->addOrUpdateCartItem($user, $product, $current + $qtyNeeded);
        }

        $summary = $this->cartService->summary($user);

        return response()->json([
            'message' => 'Selected recipe ingredients added to cart successfully.',
            'data' => [
                'items' => $summary['items'],
                'item_count' => $summary['item_count'],
                'subtotal' => $summary['subtotal'],
                'total_discount' => $summary['total_discount'],
                'total' => $summary['total'],
            ],
        ]);
    }

    private function canViewForIngredientCart(Recipe $recipe, User $user): bool
    {
        if ($recipe->status === Recipe::STATUS_APPROVED) {
            return true;
        }

        if ($user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return $recipe->chef_id === $user->id;
    }

    private function relatedRecipes(Recipe $recipe)
    {
        $chefNicheId = $recipe->chef?->chef_niche_id;

        return $this->basePublicQuery()
            ->where('id', '!=', $recipe->id)
            ->where(function (Builder $query) use ($recipe, $chefNicheId): void {
                $query->where('chef_id', $recipe->chef_id);

                if ($chefNicheId) {
                    $query->orWhereHas('chef', function (Builder $chefQuery) use ($chefNicheId): void {
                        $chefQuery->where('chef_niche_id', $chefNicheId);
                    });
                }
            })
            ->orderByDesc('is_recommended')
            ->orderByDesc('bookmarked_by_users_count')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();
    }

    private function basePublicQuery(): Builder
    {
        return Recipe::query()
            ->where('status', Recipe::STATUS_APPROVED)
            ->with([
                'chef:id,name,chef_name,username,profile_picture,chef_niche_id',
                'chef.chefNiche:id,name,slug',
            ])
            ->withCount(['bookmarkedByUsers', 'ingredients']);
    }
}

