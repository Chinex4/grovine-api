<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_endpoint_handles_cors_preflight_requests(): void
    {
        config()->set('cors.allowed_origins', ['https://console.grovine.ng']);
        config()->set('cors.supports_credentials', true);

        $this->withHeaders([
            'Origin' => 'https://console.grovine.ng',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ])->call('OPTIONS', '/api/auth/admin/login')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://console.grovine.ng')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_admin_can_login_with_email_and_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
            'password' => Hash::make('Secret123!'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/admin/login', [
            'email' => $admin->email,
            'password' => 'Secret123!',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Admin login successful.')
            ->assertJsonPath('data.user.email', 'admin@example.com')
            ->assertJsonPath('data.user.role', User::ROLE_ADMIN)
            ->assertJsonStructure([
                'data' => ['access_token', 'expires_at', 'user'],
            ]);
    }

    public function test_admin_can_change_password_and_login_with_new_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => Hash::make('Secret123!'),
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($admin)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/auth/admin/change-password', [
            'current_password' => 'Secret123!',
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Admin password changed successfully.');

        $this->postJson('/api/auth/admin/login', [
            'email' => $admin->email,
            'password' => 'Secret123!',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid admin credentials.');

        $this->postJson('/api/auth/admin/login', [
            'email' => $admin->email,
            'password' => 'NewSecret456!',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Admin login successful.');
    }

    public function test_non_admin_and_blocked_admin_cannot_login_through_admin_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00'));

        User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'user@example.com',
            'password' => Hash::make('Secret123!'),
            'email_verified_at' => now(),
        ]);

        $suspendedAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'suspended-admin@example.com',
            'password' => Hash::make('Secret123!'),
            'email_verified_at' => now(),
            'account_status' => User::ACCOUNT_STATUS_SUSPENDED,
            'suspended_until' => now()->addDay(),
        ]);

        $this->postJson('/api/auth/admin/login', [
            'email' => 'user@example.com',
            'password' => 'Secret123!',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid admin credentials.');

        $this->postJson('/api/auth/admin/login', [
            'email' => $suspendedAdmin->email,
            'password' => 'Secret123!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This admin account is suspended until Thu, Mar 19, 2026 10:00 AM.');

        Carbon::setTestNow();
    }
}
