<?php

namespace Tests\Feature;

use App\Models\ChefNiche;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserDailyActivity;
use App\Services\JwtService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_users_and_fetch_growth_and_activity_charts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));
        Storage::fake('public');
        config(['notification_channels.email.enabled' => false]);

        $adminToken = $this->tokenForRole(User::ROLE_ADMIN);
        $headers = ['Authorization' => 'Bearer '.$adminToken];

        $niche = ChefNiche::query()->create([
            'name' => 'Soups',
            'slug' => 'soups',
            'description' => 'Soup recipes',
            'is_active' => true,
        ]);

        $unverifiedUser = User::factory()->unverified()->create([
            'name' => 'Draft User',
            'email' => 'draft@example.com',
            'created_at' => now()->subDays(2),
        ]);

        $chefUser = User::factory()->create([
            'role' => User::ROLE_CHEF,
            'chef_name' => 'Chef Tega',
            'chef_niche_id' => $niche->id,
            'created_at' => now()->subDay(),
            'last_seen_at' => now()->subHours(4),
        ]);
        $chefUser->chefNiches()->sync([$niche->id]);

        Recipe::query()->create([
            'chef_id' => $chefUser->id,
            'title' => 'Egusi',
            'slug' => 'egusi',
            'status' => Recipe::STATUS_APPROVED,
        ]);

        UserDailyActivity::query()->create([
            'user_id' => $chefUser->id,
            'activity_date' => now()->subDay()->toDateString(),
            'hits' => 3,
            'last_seen_at' => now()->subDay()->setTime(12, 0),
        ]);

        UserDailyActivity::query()->create([
            'user_id' => $unverifiedUser->id,
            'activity_date' => now()->subDays(2)->toDateString(),
            'hits' => 1,
            'last_seen_at' => now()->subDays(2)->setTime(8, 0),
        ]);

        $listResponse = $this->withHeaders($headers)
            ->getJson('/api/admin/users?verification_status=unverified');

        $listResponse
            ->assertOk()
            ->assertJsonPath('message', 'Users fetched successfully.')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.email', 'draft@example.com')
            ->assertJsonPath('data.items.0.verification_status', 'unverified');

        $createResponse = $this->withHeaders($headers)->post('/api/admin/users', [
            'name' => 'Mary Admin',
            'username' => 'Mary_Admin',
            'email' => 'mary@example.com',
            'phone' => '+2348011111111',
            'address' => 'Lagos, Nigeria',
            'role' => User::ROLE_CHEF,
            'chef_name' => 'Chef Mary',
            'chef_niche_id' => $niche->id,
            'onboarding_completed' => true,
            'is_verified' => true,
            'profile_picture' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.email', 'mary@example.com')
            ->assertJsonPath('data.role', User::ROLE_CHEF)
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.account_status', User::ACCOUNT_STATUS_ACTIVE)
            ->assertJsonPath('data.stats.posts_created_count', 0);

        $managedUserId = (string) $createResponse->json('data.id');

        $this->withHeaders($headers)
            ->getJson('/api/admin/users/'.$managedUserId)
            ->assertOk()
            ->assertJsonPath('message', 'User fetched successfully.')
            ->assertJsonPath('data.username', 'mary_admin')
            ->assertJsonPath('data.chef_niches.0.id', $niche->id);

        $this->withHeaders($headers)
            ->patchJson('/api/admin/users/'.$managedUserId, [
                'name' => 'Mary Updated',
                'role' => User::ROLE_USER,
                'is_verified' => false,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User updated successfully.')
            ->assertJsonPath('data.name', 'Mary Updated')
            ->assertJsonPath('data.role', User::ROLE_USER)
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.chef_name', null);

        $this->withHeaders($headers)
            ->postJson('/api/admin/users/'.$managedUserId.'/warnings', [
                'message' => 'Please update your profile details.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Warning sent successfully.')
            ->assertJsonPath('data.user.warning_count', 1)
            ->assertJsonPath('data.summary.in_app', 1);

        $suspendResponse = $this->withHeaders($headers)
            ->postJson('/api/admin/users/'.$managedUserId.'/suspend', [
                'duration' => '7_days',
                'reason' => 'Spam activity',
            ]);

        $suspendResponse
            ->assertOk()
            ->assertJsonPath('message', 'User suspended successfully.')
            ->assertJsonPath('data.account_status', User::ACCOUNT_STATUS_SUSPENDED)
            ->assertJsonPath('data.suspension_reason', 'Spam activity');

        $this->withHeaders($headers)
            ->postJson('/api/admin/users/'.$managedUserId.'/activate')
            ->assertOk()
            ->assertJsonPath('message', 'User activated successfully.')
            ->assertJsonPath('data.account_status', User::ACCOUNT_STATUS_ACTIVE)
            ->assertJsonPath('data.suspended_until', null);

        $this->withHeaders($headers)
            ->postJson('/api/admin/users/'.$managedUserId.'/ban', [
                'reason' => 'Fraudulent activity',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User banned successfully.')
            ->assertJsonPath('data.account_status', User::ACCOUNT_STATUS_BANNED)
            ->assertJsonPath('data.banned_reason', 'Fraudulent activity');

        $growthResponse = $this->withHeaders($headers)
            ->getJson('/api/admin/users/charts/growth?period=7d');

        $growthResponse
            ->assertOk()
            ->assertJsonPath('message', 'User growth chart fetched successfully.')
            ->assertJsonPath('data.summary.new_users_in_range', 4);

        $this->assertSame(
            1,
            collect($growthResponse->json('data.series'))
                ->firstWhere('date', now()->subDays(2)->toDateString())['new_users']
        );

        $activityResponse = $this->withHeaders($headers)
            ->getJson('/api/admin/users/charts/activity?period=7d');

        $activityResponse
            ->assertOk()
            ->assertJsonPath('message', 'User activity chart fetched successfully.')
            ->assertJsonPath('data.summary.total_hits_in_range', 4);

        $this->assertSame(
            3,
            collect($activityResponse->json('data.series'))
                ->firstWhere('date', now()->subDay()->toDateString())['hits']
        );

        $this->withHeaders($headers)
            ->deleteJson('/api/admin/users/'.$managedUserId)
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully.');

        $this->assertDatabaseMissing('users', [
            'id' => $managedUserId,
        ]);

        Carbon::setTestNow();
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $token = $this->tokenForRole(User::ROLE_USER);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');
    }

    public function test_suspended_and_banned_users_are_blocked_from_authentication(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));

        $suspendedUser = User::factory()->create([
            'email' => 'suspended@example.com',
            'account_status' => User::ACCOUNT_STATUS_SUSPENDED,
            'suspended_until' => now()->addDay(),
            'email_verified_at' => now(),
        ]);

        $bannedUser = User::factory()->create([
            'email' => 'banned@example.com',
            'account_status' => User::ACCOUNT_STATUS_BANNED,
            'banned_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $bannedUser->email,
        ])->assertStatus(403)
            ->assertJsonPath('message', 'This account has been banned by an administrator.');

        $suspendedToken = app(JwtService::class)->issueForUser($suspendedUser)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$suspendedToken,
        ])->getJson('/api/user/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account is suspended until Tue, Mar 17, 2026 10:00 AM.');

        Carbon::setTestNow();
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
