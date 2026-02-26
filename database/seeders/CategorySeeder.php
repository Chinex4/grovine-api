<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Vegetables', 'description' => 'Fresh and healthy vegetables', 'image_url' => 'categories/vegetables.jpg', 'sort_order' => 1],
            ['name' => 'Fruits', 'description' => 'Fresh fruits and fruit baskets', 'image_url' => 'categories/fruits.jpg', 'sort_order' => 2],
            ['name' => 'Baby & Kids', 'description' => 'Kids friendly groceries and snacks', 'image_url' => 'categories/baby-kids.jpg', 'sort_order' => 3],
            ['name' => 'Proteins', 'description' => 'Protein-rich foods', 'image_url' => 'categories/proteins.jpg', 'sort_order' => 4],
            ['name' => 'Grains', 'description' => 'Rice, oats and grain products', 'image_url' => 'categories/grains.jpg', 'sort_order' => 5],
            ['name' => 'Baked Foods', 'description' => 'Bread and baked items', 'image_url' => 'categories/baked-foods.jpg', 'sort_order' => 6],
            ['name' => 'Beverages', 'description' => 'Drinks and refreshment items', 'image_url' => 'categories/beverages.jpg', 'sort_order' => 7],
            ['name' => 'Snacks & Treats', 'description' => 'Quick snacks and treats', 'image_url' => 'categories/snacks-treats.jpg', 'sort_order' => 8],
            ['name' => 'Frozen Foods', 'description' => 'Frozen grocery products', 'image_url' => 'categories/frozen-foods.jpg', 'sort_order' => 9],
            ['name' => 'House Essentials', 'description' => 'Everyday house essentials', 'image_url' => 'categories/house-essentials.jpg', 'sort_order' => 10],
            ['name' => 'Desserts & Sweetners', 'description' => 'Desserts and sweet items', 'image_url' => 'categories/desserts-sweetners.jpg', 'sort_order' => 11],
            ['name' => 'International Cuisines', 'description' => 'Ingredients from global cuisines', 'image_url' => 'categories/international-cuisines.jpg', 'sort_order' => 12],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(
                ['slug' => str()->slug($category['name'])],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'image_url' => $category['image_url'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
