<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@grovine.ng'],
            [
                'name' => 'Admin User',
                'username' => 'admin_user_1001',
                'role' => User::ROLE_ADMIN,
                'email_verified_at' => now(),
                'onboarding_completed' => true,
            ]
        );
    }
}
