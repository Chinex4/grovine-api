<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PreferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthOnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_signup_verify_and_set_preferences_with_jwt_authentication(): void
    {
        config()->set('otp.debug_expose_code', true);

        $this->seed(PreferenceSeeder::class);
        User::factory()->create([
            'name' => 'Referrer User',
            'email' => 'referrer@example.com',
            'referral_code' => 'GRV123',
        ]);

        $signupResponse = $this->postJson('/api/auth/signup', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+2348012345678',
            'referral_code' => 'GRV123',
        ]);

        $signupResponse
            ->assertCreated()
            ->assertJsonPath('message', 'OTP sent successfully.')
            ->assertJsonPath('data.otp_length', 5);

        $signupOtp = (string) $signupResponse->json('data.otp');

        $verifySignupResponse = $this->postJson('/api/auth/verify-signup-otp', [
            'email' => 'jane@example.com',
            'otp' => $signupOtp,
        ]);

        $verifySignupResponse
            ->assertOk()
            ->assertJsonPath('message', 'Account verified successfully.')
            ->assertJsonPath('data.user.onboarding_completed', false);

        $userId = (string) $verifySignupResponse->json('data.user.id');
        $token = (string) $verifySignupResponse->json('data.access_token');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $userId
        );

        $foodsResponse = $this->getJson('/api/preferences/favorite-foods')->assertOk();
        $regionsResponse = $this->getJson('/api/preferences/cuisine-regions')->assertOk();

        $foodIds = collect($foodsResponse->json('data'))->take(2)->pluck('id')->all();
        $regionIds = collect($regionsResponse->json('data'))->take(2)->pluck('id')->all();

        $this->postJson('/api/onboarding/preferences', [
            'favorite_food_ids' => $foodIds,
            'cuisine_region_ids' => $regionIds,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.onboarding_completed', true);
    }

    public function test_user_can_login_and_verify_otp(): void
    {
        config()->set('otp.debug_expose_code', true);

        $signupResponse = $this->postJson('/api/auth/signup', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $signupOtp = (string) $signupResponse->json('data.otp');

        $this->postJson('/api/auth/verify-signup-otp', [
            'email' => 'john@example.com',
            'otp' => $signupOtp,
        ])->assertOk();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
        ])->assertOk();

        $loginOtp = (string) $loginResponse->json('data.otp');

        $this->postJson('/api/auth/verify-login-otp', [
            'email' => 'john@example.com',
            'otp' => $loginOtp,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.');
    }
}
