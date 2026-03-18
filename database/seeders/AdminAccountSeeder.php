<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
                'password' => Hash::make((string) env('ADMIN_DEFAULT_PASSWORD', 'ChangeMe123!')),
                'email_verified_at' => now(),
                'onboarding_completed' => true,
            ]
        );
    }
}
