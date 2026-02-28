<?php

namespace Tests\Feature;

use App\Models\ChefNiche;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChefNicheAndProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_fetch_only_active_chef_niches(): void
    {
        ChefNiche::query()->create([
            'name' => 'Pastry',
            'slug' => 'pastry',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ChefNiche::query()->create([
            'name' => 'Hidden Niche',
            'slug' => 'hidden-niche',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/niches')
            ->assertOk()
            ->assertJsonPath('message', 'Chef niches fetched successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'pastry');
    }

    public function test_admin_can_crud_chef_niches_and_non_admin_cannot_manage(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ]);
        $adminToken = app(JwtService::class)->issueForUser($admin)['token'];

        $create = $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
        ])->postJson('/api/admin/niches', [
            'name' => 'Soups & Stews',
            'description' => 'Warm bowl meals',
            'sort_order' => 5,
        ]);

        $create->assertCreated()
            ->assertJsonPath('message', 'Chef niche created successfully.')
            ->assertJsonPath('data.slug', 'soups-stews');

        $nicheId = (string) $create->json('data.id');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
        ])->patchJson('/api/admin/niches/'.$nicheId, [
            'name' => 'Soups and Stews',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Chef niche updated successfully.')
            ->assertJsonPath('data.slug', 'soups-and-stews');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
        ])->deleteJson('/api/admin/niches/'.$nicheId)
            ->assertOk()
            ->assertJsonPath('message', 'Chef niche deleted successfully.');

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);
        $userToken = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$userToken,
        ])->postJson('/api/admin/niches', [
            'name' => 'Unauthorized',
        ])->assertStatus(403)->assertJsonPath('message', 'Forbidden.');
    }

    public function test_user_can_become_chef_and_share_public_profile(): void
    {
        $niche = ChefNiche::query()->create([
            'name' => 'Grilling',
            'slug' => 'grilling',
            'description' => 'Barbecue and grills',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $secondaryNiche = ChefNiche::query()->create([
            'name' => 'Desserts',
            'slug' => 'desserts',
            'description' => 'Sweet dishes',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $user = User::factory()->create([
            'name' => 'Chef Candidate',
            'username' => 'chef_candidate_123',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);
        $token = app(JwtService::class)->issueForUser($user)['token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/chef/become', [
            'chef_name' => 'Chef K',
            'chef_niche_ids' => [$niche->id, $secondaryNiche->id],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Chef profile updated successfully.')
            ->assertJsonPath('data.role', User::ROLE_CHEF)
            ->assertJsonPath('data.chef_name', 'Chef K')
            ->assertJsonPath('data.chef_niche.id', $niche->id)
            ->assertJsonCount(2, 'data.chef_niches');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_CHEF,
            'chef_name' => 'Chef K',
            'chef_niche_id' => $niche->id,
        ]);
        $this->assertDatabaseHas('chef_niche_user', [
            'user_id' => $user->id,
            'chef_niche_id' => $niche->id,
        ]);
        $this->assertDatabaseHas('chef_niche_user', [
            'user_id' => $user->id,
            'chef_niche_id' => $secondaryNiche->id,
        ]);

        $this->getJson('/api/chefs/chef_candidate_123')
            ->assertOk()
            ->assertJsonPath('message', 'Chef profile fetched successfully.')
            ->assertJsonPath('data.username', 'chef_candidate_123')
            ->assertJsonPath('data.chef_name', 'Chef K')
            ->assertJsonPath('data.chef_niche.slug', 'grilling')
            ->assertJsonCount(2, 'data.chef_niches')
            ->assertJsonFragment(['slug' => 'desserts']);
    }
}
