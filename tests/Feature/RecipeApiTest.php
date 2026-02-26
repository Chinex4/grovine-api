<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChefNiche;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_flow_for_chef_admin_and_users(): void
    {
        Storage::fake('public');

        $niche = ChefNiche::query()->create([
            'name' => 'Soups',
            'slug' => 'soups',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $chef = User::factory()->create([
            'role' => User::ROLE_CHEF,
            'chef_name' => 'Chef Rich',
            'chef_niche_id' => $niche->id,
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $chefToken = app(JwtService::class)->issueForUser($chef)['token'];
        $adminToken = app(JwtService::class)->issueForUser($admin)['token'];
        $userToken = app(JwtService::class)->issueForUser($user)['token'];

        $category = Category::query()->create([
            'name' => 'Vegetables',
            'slug' => 'vegetables',
            'is_active' => true,
        ]);

        $onion = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Red Onion',
            'slug' => 'red-onion',
            'description' => 'Fresh onion',
            'image_url' => 'products/onion.jpg',
            'price' => 1000,
            'stock' => 20,
            'discount' => 0,
            'is_active' => true,
        ]);

        $pepper = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Bell Pepper',
            'slug' => 'bell-pepper',
            'description' => 'Fresh pepper',
            'image_url' => 'products/pepper.jpg',
            'price' => 1200,
            'stock' => 20,
            'discount' => 0,
            'is_active' => true,
        ]);

        $create = $this->withHeaders([
            'Authorization' => 'Bearer '.$chefToken,
        ])->post('/api/chef/recipes', [
            'title' => 'Egusi Soup With Chicken',
            'short_description' => 'A rich and spicy soup.',
            'instructions' => 'Step 1: Prep. Step 2: Cook. Step 3: Serve.',
            'duration_seconds' => 540,
            'servings' => 2,
            'estimated_cost' => 20000,
            'is_quick_recipe' => true,
            'video' => UploadedFile::fake()->create('recipe.mp4', 2048, 'video/mp4'),
            'cover_image' => UploadedFile::fake()->image('cover.jpg'),
            'ingredients' => [
                [
                    'item_text' => '2 red onions, diced',
                    'product_id' => $onion->id,
                    'cart_quantity' => 2,
                ],
                [
                    'item_text' => '1 bell pepper, chopped',
                    'product_id' => $pepper->id,
                    'cart_quantity' => 1,
                ],
            ],
            'submit' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('message', 'Recipe saved successfully.')
            ->assertJsonPath('data.status', Recipe::STATUS_PENDING_APPROVAL)
            ->assertJsonPath('data.ingredients.0.item_text', '2 red onions, diced');

        $recipeId = (string) $create->json('data.id');
        $firstIngredientId = (string) $create->json('data.ingredients.0.id');
        $secondIngredientId = (string) $create->json('data.ingredients.1.id');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
        ])->patchJson('/api/admin/recipes/'.$recipeId.'/review', [
            'action' => 'approve',
            'is_recommended' => true,
            'is_quick_recipe' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Recipe::STATUS_APPROVED)
            ->assertJsonPath('data.is_recommended', true);

        Recipe::query()->create([
            'chef_id' => $chef->id,
            'status' => Recipe::STATUS_APPROVED,
            'title' => 'Chicken Stew',
            'slug' => 'chicken-stew',
            'short_description' => 'Stew recipe',
            'instructions' => 'Cook slowly.',
            'video_url' => 'recipes/videos/chicken.mp4',
            'cover_image_url' => 'recipes/covers/chicken.jpg',
            'published_at' => now(),
            'approved_at' => now(),
        ]);

        $this->getJson('/api/recipes')
            ->assertOk()
            ->assertJsonPath('message', 'Recipes fetched successfully.')
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/recipes/recommended')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recipeId);

        $this->getJson('/api/recipes/quick')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recipeId);

        $this->getJson('/api/recipes/'.$recipeId)
            ->assertOk()
            ->assertJsonPath('message', 'Recipe fetched successfully.')
            ->assertJsonPath('data.recipe.id', $recipeId)
            ->assertJsonPath('data.related_recipes.0.title', 'Chicken Stew');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->postJson('/api/recipes/'.$recipeId.'/bookmark')
            ->assertOk()
            ->assertJsonPath('message', 'Recipe bookmarked successfully.');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->getJson('/api/recipes/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recipeId);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->postJson('/api/recipes/'.$recipeId.'/ingredients/add-to-cart', [
            'ingredient_ids' => [$firstIngredientId, $secondIngredientId],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Selected recipe ingredients added to cart successfully.')
            ->assertJsonPath('data.item_count', 3);

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $onion->id,
            'quantity' => 2,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->deleteJson('/api/recipes/'.$recipeId.'/bookmark')
            ->assertOk()
            ->assertJsonPath('message', 'Recipe bookmark removed successfully.');
    }

    public function test_chef_can_manage_own_recipes_and_non_chef_is_forbidden(): void
    {
        Storage::fake('public');

        $niche = ChefNiche::query()->create([
            'name' => 'Grills',
            'slug' => 'grills',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $chef = User::factory()->create([
            'role' => User::ROLE_CHEF,
            'chef_name' => 'Chef C',
            'chef_niche_id' => $niche->id,
            'email_verified_at' => now(),
        ]);
        $otherChef = User::factory()->create([
            'role' => User::ROLE_CHEF,
            'email_verified_at' => now(),
        ]);
        $normalUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $chefToken = app(JwtService::class)->issueForUser($chef)['token'];
        $otherChefToken = app(JwtService::class)->issueForUser($otherChef)['token'];
        $userToken = app(JwtService::class)->issueForUser($normalUser)['token'];

        $recipe = Recipe::query()->create([
            'chef_id' => $chef->id,
            'status' => Recipe::STATUS_APPROVED,
            'title' => 'Chef Recipe',
            'slug' => 'chef-recipe',
            'short_description' => 'Desc',
            'instructions' => 'Instructions',
            'video_url' => 'recipes/videos/a.mp4',
            'cover_image_url' => 'recipes/covers/a.jpg',
            'approved_at' => now(),
            'published_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$chefToken,
        ])->getJson('/api/chef/recipes')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$chefToken,
        ])->patchJson('/api/chef/recipes/'.$recipe->id, [
            'short_description' => 'Updated desc',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Recipe::STATUS_PENDING_APPROVAL);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$otherChefToken,
        ])->getJson('/api/chef/recipes/'.$recipe->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->post('/api/chef/recipes', [
            'title' => 'Not allowed',
        ])->assertStatus(403)->assertJsonPath('message', 'Forbidden.');
    }
}

