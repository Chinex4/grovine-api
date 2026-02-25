<?php

namespace Database\Seeders;

use App\Models\CuisineRegion;
use App\Models\FavoriteFood;
use Illuminate\Database\Seeder;

class PreferenceSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $foods = [
            'Seafood',
            'Rice Dishes',
            'Soups & Stews',
            'Vegetarian Meals',
            'Barbecue & Grills',
            'Pasta',
            'Fries & Grills',
        ];

        foreach ($foods as $food) {
            FavoriteFood::query()->updateOrCreate(
                ['slug' => str()->slug($food)],
                ['name' => $food, 'is_active' => true]
            );
        }

        $regions = [
            'Italy',
            'Nigeria',
            'China',
            'Mexico',
            'India',
            'South Africa',
            'USA',
            'Korea',
            'Ghana',
        ];

        foreach ($regions as $region) {
            CuisineRegion::query()->updateOrCreate(
                ['slug' => str()->slug($region)],
                ['name' => $region, 'is_active' => true]
            );
        }
    }
}
