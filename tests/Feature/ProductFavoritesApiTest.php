<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFavoritesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_user_can_toggle_product_favorite_and_fetch_favorites(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Grape',
            'slug' => 'grape',
            'description' => 'Sweet red grape',
            'image_url' => 'products/grape.jpg',
            'price' => 4800.79,
            'stock' => 20,
            'discount' => 100,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/products/'.$product->id.'/favorite')
            ->assertOk()
            ->assertJsonPath('message', 'Product favorite status updated successfully.')
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.is_favorited', true);

        $this->assertDatabaseHas('favorite_product_user', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/products/favorites')
            ->assertOk()
            ->assertJsonPath('message', 'Favorite products fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.image_url', $baseUrl.'/storage/products/grape.jpg');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/products/'.$product->id.'/favorite')
            ->assertOk()
            ->assertJsonPath('data.is_favorited', false);

        $this->assertDatabaseMissing('favorite_product_user', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_cannot_favorite_inactive_or_hidden_product(): void
    {
        $inactiveCategory = Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'is_active' => false,
        ]);

        $inactiveProduct = Product::query()->create([
            'category_id' => $inactiveCategory->id,
            'name' => 'Hidden Product',
            'slug' => 'hidden-product',
            'description' => 'Not visible',
            'image_url' => 'products/hidden.jpg',
            'price' => 1000,
            'stock' => 20,
            'discount' => 0,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/products/'.$inactiveProduct->id.'/favorite')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Product not found.');
    }

    public function test_guest_cannot_toggle_or_fetch_product_favorites(): void
    {
        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Grape',
            'slug' => 'grape',
            'description' => 'Sweet red grape',
            'image_url' => 'products/grape.jpg',
            'price' => 4800.79,
            'stock' => 20,
            'discount' => 100,
            'is_active' => true,
        ]);

        $this->getJson('/api/products/favorites')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized.');

        $this->postJson('/api/products/'.$product->id.'/favorite')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized.');
    }
}
