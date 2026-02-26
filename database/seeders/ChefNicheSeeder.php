<?php

namespace Database\Seeders;

use App\Models\ChefNiche;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ChefNicheSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            ['name' => 'Nigerian Cuisine', 'description' => 'Traditional and modern Nigerian dishes.', 'sort_order' => 1],
            ['name' => 'Pastry & Baking', 'description' => 'Bread, cakes, pastries, and baked treats.', 'sort_order' => 2],
            ['name' => 'Soups & Stews', 'description' => 'Rich soups, sauces, and hearty stews.', 'sort_order' => 3],
            ['name' => 'Grills & Barbecue', 'description' => 'Smoked, grilled, and barbecue specialties.', 'sort_order' => 4],
            ['name' => 'Seafood', 'description' => 'Fish, prawns, and seafood-focused dishes.', 'sort_order' => 5],
            ['name' => 'Vegan & Vegetarian', 'description' => 'Plant-based and vegetarian meals.', 'sort_order' => 6],
            ['name' => 'Desserts', 'description' => 'Sweet dishes, puddings, and confectionery.', 'sort_order' => 7],
            ['name' => 'Continental', 'description' => 'International and continental cuisine.', 'sort_order' => 8],
        ];

        foreach ($items as $item) {
            ChefNiche::query()->updateOrCreate(
                ['slug' => Str::slug($item['name'])],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'is_active' => true,
                    'sort_order' => $item['sort_order'],
                ]
            );
        }
    }
}

