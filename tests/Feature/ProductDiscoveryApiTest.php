<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_fetch_all_products_and_categories(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $activeCategory = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'description' => 'Fresh fruits',
            'image_url' => 'categories/fruits.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $inactiveCategory = Category::query()->create([
            'name' => 'Inactive Cat',
            'slug' => 'inactive-cat',
            'is_active' => false,
        ]);

        Product::query()->create([
            'category_id' => $activeCategory->id,
            'name' => 'Grape',
            'slug' => 'grape',
            'description' => 'Sweet red grape',
            'image_url' => 'products/grape.jpg',
            'price' => 4800.79,
            'stock' => 20,
            'discount' => 100,
            'is_active' => true,
        ]);

        Product::query()->create([
            'category_id' => $activeCategory->id,
            'name' => 'Hidden Product',
            'slug' => 'hidden-product',
            'description' => 'Should not show',
            'image_url' => 'products/hidden.jpg',
            'price' => 800,
            'stock' => 20,
            'discount' => 0,
            'is_active' => false,
        ]);

        Product::query()->create([
            'category_id' => $inactiveCategory->id,
            'name' => 'Blocked by Category',
            'slug' => 'blocked-by-category',
            'description' => 'Should not show',
            'image_url' => 'products/blocked.jpg',
            'price' => 500,
            'stock' => 20,
            'discount' => 0,
            'is_active' => true,
        ]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('message', 'Categories fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fruits')
            ->assertJsonPath('data.0.image_url', $baseUrl.'/storage/categories/fruits.jpg');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('message', 'Products fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Grape')
            ->assertJsonPath('data.0.image_url', $baseUrl.'/storage/products/grape.jpg')
            ->assertJsonPath('data.0.category.name', 'Fruits');
    }

    public function test_search_recommended_and_rush_hour_offer_endpoints(): void
    {
        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Apple',
            'slug' => 'apple',
            'description' => 'Green apple',
            'image_url' => 'products/apple.jpg',
            'price' => 4800.79,
            'stock' => 100,
            'discount' => 120,
            'is_active' => true,
            'is_recommended' => true,
            'is_rush_hour_offer' => false,
        ]);

        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Grape',
            'slug' => 'grape',
            'description' => 'Red grape',
            'image_url' => 'products/grape.jpg',
            'price' => 4800.79,
            'stock' => 100,
            'discount' => 500,
            'is_active' => true,
            'is_recommended' => false,
            'is_rush_hour_offer' => true,
            'rush_hour_starts_at' => now()->subHour(),
            'rush_hour_ends_at' => now()->addHour(),
        ]);

        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Expired Offer',
            'slug' => 'expired-offer',
            'description' => 'Old promo',
            'image_url' => 'products/expired.jpg',
            'price' => 4800.79,
            'stock' => 100,
            'discount' => 300,
            'is_active' => true,
            'is_rush_hour_offer' => true,
            'rush_hour_starts_at' => now()->subDays(2),
            'rush_hour_ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/products/search?q=app')
            ->assertOk()
            ->assertJsonPath('message', 'Search results fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Apple');

        $this->getJson('/api/search?q=gra')
            ->assertOk()
            ->assertJsonPath('message', 'Search results fetched successfully.')
            ->assertJsonPath('data.0.name', 'Grape');

        $this->getJson('/api/products/recommended')
            ->assertOk()
            ->assertJsonPath('message', 'Recommended products fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Apple');

        $this->getJson('/api/products/rush-hour-offers')
            ->assertOk()
            ->assertJsonPath('message', 'Rush hour offers fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Grape');
    }
}
