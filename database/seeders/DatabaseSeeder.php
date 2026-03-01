<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PreferenceSeeder::class,
            ChefNicheSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            RecipeSeeder::class,
            AdSeeder::class,
            AdminAccountSeeder::class,
        ]);

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'username' => 'test_user_1001',
                'role' => User::ROLE_USER,
                'email_verified_at' => now(),
            ]
        );

    }
}
