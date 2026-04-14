<?php

namespace Database\Seeders;

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
            PlayStoreTestAccountSeeder::class,
        ]);
    }
}
