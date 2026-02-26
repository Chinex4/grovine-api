<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_categories_and_products(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        Storage::fake('public');

        $token = $this->tokenForRole(User::ROLE_ADMIN);

        $createCategory = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/categories', [
            'name' => 'Fruits',
            'description' => 'Fresh fruits',
            'image' => UploadedFile::fake()->image('fruits.jpg'),
            'sort_order' => 2,
        ]);

        $createCategory
            ->assertCreated()
            ->assertJsonPath('message', 'Category created successfully.')
            ->assertJsonPath('data.name', 'Fruits');

        $categoryId = (string) $createCategory->json('data.id');
        $categoryImageUrl = (string) $createCategory->json('data.image_url');
        $this->assertStringStartsWith($baseUrl.'/storage/categories/', $categoryImageUrl);

        $createProduct = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/products', [
            'name' => 'Grape Basket',
            'description' => 'Fresh grape basket',
            'image' => UploadedFile::fake()->image('grape.jpg'),
            'price' => 4800.79,
            'category_id' => $categoryId,
            'stock' => 15,
            'discount' => 200,
            'is_recommended' => true,
            'is_rush_hour_offer' => true,
            'rush_hour_starts_at' => now()->subMinutes(20)->toIso8601String(),
            'rush_hour_ends_at' => now()->addMinutes(20)->toIso8601String(),
        ]);

        $createProduct
            ->assertCreated()
            ->assertJsonPath('message', 'Product created successfully.')
            ->assertJsonPath('data.name', 'Grape Basket')
            ->assertJsonPath('data.category.id', $categoryId);

        $productId = (string) $createProduct->json('data.id');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->patchJson('/api/admin/products/'.$productId, [
            'stock' => 100,
            'discount' => 150,
            'name' => 'Grape Basket Premium',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Product updated successfully.')
            ->assertJsonPath('data.stock', 100)
            ->assertJsonPath('data.name', 'Grape Basket Premium');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->deleteJson('/api/admin/products/'.$productId)
            ->assertOk()
            ->assertJsonPath('message', 'Product deleted successfully.');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->deleteJson('/api/admin/categories/'.$categoryId)
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted successfully.');
    }

    public function test_non_admin_cannot_manage_categories_or_products(): void
    {
        $token = $this->tokenForRole(User::ROLE_USER);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/categories', [
            'name' => 'Blocked Category',
        ])->assertStatus(403)->assertJsonPath('message', 'Forbidden.');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/products', [
            'name' => 'Blocked Product',
            'price' => 100,
            'stock' => 10,
            'category_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(403)->assertJsonPath('message', 'Forbidden.');
    }

    private function tokenForRole(string $role): string
    {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        return app(JwtService::class)->issueForUser($user)['token'];
    }
}
