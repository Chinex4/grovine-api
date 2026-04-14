<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Database\Seeders\PlayStoreTestAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlayStoreTestLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_play_store_account_can_login_with_fixed_otp(): void
    {
        Mail::fake();

        config()->set('otp.debug_expose_code', false);
        config()->set('otp.test_login', [
            'enabled' => true,
            'email' => 'playstore.reviewer@grovine.ng',
            'name' => 'Play Store Reviewer',
            'code' => '55555',
        ]);

        $this->seed(PlayStoreTestAccountSeeder::class);

        $user = User::query()->where('email', 'playstore.reviewer@grovine.ng')->first();

        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->email_verified_at);
        $this->assertTrue($user->onboarding_completed);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'playstore.reviewer@grovine.ng',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('message', 'OTP sent successfully.')
            ->assertJsonPath('data.otp_length', 5)
            ->assertJsonPath('data.otp_delivery_channel', 'fixed_test_code')
            ->assertJsonPath('data.uses_test_otp', true);

        $this->assertNull($loginResponse->json('data.otp'));

        Mail::assertNothingSent();

        $otp = OtpCode::query()->where('user_id', $user->id)->where('purpose', 'login')->latest()->first();

        $this->assertNotNull($otp);
        $this->assertTrue(hash_equals($otp->code_hash, hash('sha256', '55555')));

        $this->postJson('/api/auth/verify-login-otp', [
            'email' => 'playstore.reviewer@grovine.ng',
            'otp' => '55555',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.email', 'playstore.reviewer@grovine.ng');
    }

    public function test_regular_user_login_still_uses_email_delivery(): void
    {
        Mail::fake();

        config()->set('otp.debug_expose_code', false);
        config()->set('otp.test_login', [
            'enabled' => true,
            'email' => 'playstore.reviewer@grovine.ng',
            'name' => 'Play Store Reviewer',
            'code' => '55555',
        ]);

        User::factory()->create([
            'email' => 'regular@example.com',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'regular@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('data.otp_delivery_channel', 'email')
            ->assertJsonPath('data.uses_test_otp', false);

        Mail::assertSent(OtpCodeMail::class, 1);
    }
}
