<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_rewards_are_paid_on_first_and_second_paid_orders_and_stats_are_available(): void
    {
        config()->set('otp.debug_expose_code', true);

        $referrer = User::factory()->create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'wallet_balance' => 0,
            'referral_code' => 'USERA1',
            'email_verified_at' => now(),
        ]);

        $signup = $this->postJson('/api/auth/signup', [
            'name' => 'User B',
            'email' => 'userb@example.com',
            'referral_code' => 'USERA1',
        ])->assertCreated();

        $otp = (string) $signup->json('data.otp');

        $verify = $this->postJson('/api/auth/verify-signup-otp', [
            'email' => 'userb@example.com',
            'otp' => $otp,
        ])->assertOk();

        $referredToken = (string) $verify->json('data.access_token');
        $referredUserId = (string) $verify->json('data.user.id');

        $referred = User::query()->findOrFail($referredUserId);
        $referred->update(['wallet_balance' => 6000]);

        $this->assertSame($referrer->id, $referred->referred_by_user_id);

        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Referral Grape',
            'slug' => 'referral-grape',
            'description' => 'Test product',
            'image_url' => 'products/referral-grape.jpg',
            'price' => 1000,
            'stock' => 50,
            'discount' => 0,
            'is_active' => true,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$referredToken])
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])->assertCreated();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$referredToken,
            'Idempotency-Key' => 'ref-order-1',
        ])->postJson('/api/checkout', [
            'payment_method' => 'wallet',
            'delivery_address' => 'Lagos',
        ])->assertCreated();

        $this->withHeaders(['Authorization' => 'Bearer '.$referredToken])
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])->assertCreated();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$referredToken,
            'Idempotency-Key' => 'ref-order-2',
        ])->postJson('/api/checkout', [
            'payment_method' => 'wallet',
            'delivery_address' => 'Lagos',
        ])->assertCreated();

        $this->assertSame('1000.00', number_format((float) $referrer->fresh()->wallet_balance, 2, '.', ''));
        $this->assertSame('4500.00', number_format((float) $referred->fresh()->wallet_balance, 2, '.', ''));

        $this->assertDatabaseCount('referral_payouts', 3);
        $this->assertDatabaseHas('referral_payouts', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'beneficiary_user_id' => $referrer->id,
            'milestone' => 'REFERRER_FIRST_ORDER',
            'amount' => 500.00,
        ]);
        $this->assertDatabaseHas('referral_payouts', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'beneficiary_user_id' => $referrer->id,
            'milestone' => 'REFERRER_SECOND_ORDER',
            'amount' => 500.00,
        ]);
        $this->assertDatabaseHas('referral_payouts', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'beneficiary_user_id' => $referred->id,
            'milestone' => 'REFERRED_FIRST_ORDER',
            'amount' => 500.00,
        ]);

        $this->assertDatabaseCount('wallet_transactions', 5);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $referrer->id,
            'type' => 'REFERRAL_BONUS',
            'direction' => 'CREDIT',
            'amount' => 500.00,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $referred->id,
            'type' => 'REFERRAL_BONUS',
            'direction' => 'CREDIT',
            'amount' => 500.00,
        ]);

        $referrerToken = app(JwtService::class)->issueForUser($referrer)['token'];

        $this->withHeaders(['Authorization' => 'Bearer '.$referrerToken])
            ->getJson('/api/referrals')
            ->assertOk()
            ->assertJsonPath('message', 'Referral details fetched successfully.')
            ->assertJsonPath('data.referral_code', 'USERA1')
            ->assertJsonPath('data.stats.total_referrals', 1)
            ->assertJsonPath('data.stats.first_order_conversions', 1)
            ->assertJsonPath('data.stats.second_order_conversions', 1)
            ->assertJsonPath('data.stats.total_referrer_bonus_earned', '1000.00')
            ->assertJsonPath('data.referred_users.0.id', $referred->id)
            ->assertJsonPath('data.referred_users.0.referrer_bonus_earned', '1000.00')
            ->assertJsonPath('data.referred_users.0.friend_bonus_earned', '500.00');
    }

    public function test_signup_rejects_invalid_referral_code(): void
    {
        config()->set('otp.debug_expose_code', true);

        $this->postJson('/api/auth/signup', [
            'name' => 'Invalid Referral',
            'email' => 'invalid-ref@example.com',
            'referral_code' => 'UNKNOWN',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid referral code.');
    }
}
