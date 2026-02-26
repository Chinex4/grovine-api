<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_fetch_only_active_ads(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        Ad::query()->create([
            'title' => 'Active Ad',
            'image_url' => 'ads/ad-1.jpg',
            'link' => 'https://example.com/one',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Ad::query()->create([
            'title' => 'Inactive Ad',
            'image_url' => 'ads/ad-2.jpg',
            'link' => 'https://example.com/two',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/ads');

        $response->assertOk()
            ->assertJsonPath('message', 'Ads fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Active Ad')
            ->assertJsonPath('data.0.image_url', $baseUrl.'/storage/ads/ad-1.jpg');
    }

    public function test_admin_can_crud_ads_with_role_based_access_and_image_upload(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        Storage::fake('public');
        $token = $this->tokenForRole(User::ROLE_ADMIN);

        $create = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/ads', [
            'title' => 'Launch Promo',
            'image' => UploadedFile::fake()->image('launch.jpg'),
            'link' => 'https://example.com/launch',
            'sort_order' => 5,
        ]);

        $create->assertCreated()->assertJsonPath('message', 'Ad created successfully.');

        $adId = (string) $create->json('data.id');
        $imageUrl = (string) $create->json('data.image_url');
        $relativePath = ltrim((string) parse_url($imageUrl, PHP_URL_PATH), '/');
        $relativePath = str_starts_with($relativePath, 'storage/') ? substr($relativePath, 8) : $relativePath;

        $this->assertStringStartsWith($baseUrl.'/storage/ads/', $imageUrl);
        Storage::disk('public')->assertExists($relativePath);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->patchJson('/api/admin/ads/'.$adId, [
            'title' => 'Launch Promo Updated',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.title', 'Launch Promo Updated');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->deleteJson('/api/admin/ads/'.$adId)
            ->assertOk()
            ->assertJsonPath('message', 'Ad deleted successfully.');
    }

    public function test_non_admin_cannot_manage_ads(): void
    {
        $token = $this->tokenForRole(User::ROLE_USER);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/admin/ads', [
            'title' => 'Unauthorized Promo',
            'image' => UploadedFile::fake()->image('unauthorized.jpg'),
        ])->assertStatus(403)->assertJsonPath('message', 'Forbidden.');

        $this->assertDatabaseCount('ads', 0);
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
