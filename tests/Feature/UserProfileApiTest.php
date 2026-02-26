<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_user_can_fetch_profile_details(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $user = User::factory()->create([
            'name' => 'Profile Tester',
            'email' => 'profile@example.com',
            'profile_picture' => 'profiles/avatar.jpg',
            'role' => User::ROLE_CHEF,
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('message', 'User profile fetched successfully.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'profile@example.com')
            ->assertJsonPath('data.role', User::ROLE_CHEF)
            ->assertJsonPath('data.profile_picture', $baseUrl.'/storage/profiles/avatar.jpg')
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.address', null);
    }

    public function test_logged_in_user_can_update_profile_details(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'phone' => '08000000000',
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->patchJson('/api/user/me', [
            'name' => 'New Name',
            'username' => 'new_name_123',
            'email' => 'NEW@EXAMPLE.COM',
            'phone' => '08111111111',
            'date_of_birth' => '1995-04-12',
            'address' => 'Guards Park, Lagos',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.username', 'new_name_123')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.phone', '08111111111')
            ->assertJsonPath('data.date_of_birth', '1995-04-12')
            ->assertJsonPath('data.address', 'Guards Park, Lagos');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'username' => 'new_name_123',
            'email' => 'new@example.com',
            'phone' => '08111111111',
            'address' => 'Guards Park, Lagos',
        ]);

        $this->assertSame('1995-04-12', $user->fresh()->date_of_birth?->toDateString());
    }

    public function test_profile_update_rejects_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'email' => 'mine@example.com',
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->patchJson('/api/user/me', [
            'email' => 'taken@example.com',
        ])
            ->assertStatus(422);
    }

    public function test_profile_update_rejects_duplicate_username(): void
    {
        User::factory()->create([
            'username' => 'taken_username',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->patchJson('/api/user/me', [
            'username' => 'taken_username',
        ])
            ->assertStatus(422);
    }

    public function test_logged_in_user_can_delete_account(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->deleteJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('message', 'Account deleted successfully.');

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'User not found for token.');
    }

    public function test_logged_in_user_can_upload_profile_picture(): void
    {
        Storage::fake('public');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->post('/api/user/profile-picture', [
            'profile_picture' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profile picture uploaded successfully.');

        $pictureUrl = (string) $response->json('data.profile_picture');
        $this->assertStringStartsWith($baseUrl.'/storage/profiles/', $pictureUrl);

        $relativePath = ltrim((string) parse_url($pictureUrl, PHP_URL_PATH), '/');
        $relativePath = str_starts_with($relativePath, 'storage/') ? substr($relativePath, 8) : $relativePath;
        Storage::disk('public')->assertExists($relativePath);
    }

    public function test_guest_cannot_fetch_profile_details(): void
    {
        $this->getJson('/api/user/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized.');
    }
}
