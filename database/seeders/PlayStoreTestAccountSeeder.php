<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PlayStoreTestAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $email = strtolower((string) config('otp.test_login.email'));

        if ($email === '') {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('otp.test_login.name', 'Play Store Reviewer'),
                'username' => 'playstore_reviewer',
                'role' => User::ROLE_USER,
                'password' => null,
                'email_verified_at' => now(),
                'onboarding_completed' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            ]
        );
    }

    private function isEnabled(): bool
    {
        return (bool) config('otp.test_login.enabled', false);
    }
}
