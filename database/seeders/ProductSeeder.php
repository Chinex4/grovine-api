<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $categories = Category::query()->get()->keyBy('slug');

        $products = [
            [
                'category_slug' => 'fruits',
                'name' => 'Grape',
                'description' => 'Sweet and juicy red grapes.',
                'image_url' => 'products/grape.jpg',
                'price' => 4800.79,
                'stock' => 55,
                'discount' => 150.00,
                'is_recommended' => true,
                'is_rush_hour_offer' => true,
                'rush_hour_starts_at' => now()->subHour(),
                'rush_hour_ends_at' => now()->addHours(4),
            ],
            [
                'category_slug' => 'fruits',
                'name' => 'Apple',
                'description' => 'Fresh red apples, crunchy and sweet.',
                'image_url' => 'products/apple.jpg',
                'price' => 4800.79,
                'stock' => 70,
                'discount' => 120.00,
                'is_recommended' => true,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'fruits',
                'name' => 'Pineapple',
                'description' => 'Tropical pineapple with rich flavor.',
                'image_url' => 'products/pineapple.jpg',
                'price' => 4800.79,
                'stock' => 40,
                'discount' => 200.00,
                'is_recommended' => true,
                'is_rush_hour_offer' => true,
                'rush_hour_starts_at' => now()->subHours(2),
                'rush_hour_ends_at' => now()->addHours(2),
            ],
            [
                'category_slug' => 'fruits',
                'name' => 'Kiwi',
                'description' => 'Green kiwi fruit packed with nutrients.',
                'image_url' => 'products/kiwi.jpg',
                'price' => 4800.79,
                'stock' => 65,
                'discount' => 100.00,
                'is_recommended' => true,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'vegetables',
                'name' => 'Carrot Pack',
                'description' => 'Fresh carrot pack for healthy meals.',
                'image_url' => 'products/carrot-pack.jpg',
                'price' => 3500.00,
                'stock' => 50,
                'discount' => 300.00,
                'is_recommended' => false,
                'is_rush_hour_offer' => true,
                'rush_hour_starts_at' => now()->subMinutes(30),
                'rush_hour_ends_at' => now()->addHours(3),
            ],
            [
                'category_slug' => 'grains',
                'name' => 'Quaker Oats',
                'description' => 'Healthy oats for breakfast and recipes.',
                'image_url' => 'products/quaker-oats.jpg',
                'price' => 5200.00,
                'stock' => 120,
                'discount' => 250.00,
                'is_recommended' => false,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'proteins',
                'name' => 'Chicken Breast',
                'description' => 'Premium fresh chicken breast cuts.',
                'image_url' => 'products/chicken-breast.jpg',
                'price' => 8200.50,
                'stock' => 35,
                'discount' => 400.00,
                'is_recommended' => false,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'beverages',
                'name' => 'Orange Juice',
                'description' => 'Natural orange juice with no added sugar.',
                'image_url' => 'products/orange-juice.jpg',
                'price' => 2700.00,
                'stock' => 90,
                'discount' => 100.00,
                'is_recommended' => false,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'snacks-treats',
                'name' => 'Mixed Nut Pack',
                'description' => 'Crunchy mixed nuts snack pack.',
                'image_url' => 'products/mixed-nut-pack.jpg',
                'price' => 4100.00,
                'stock' => 80,
                'discount' => 180.00,
                'is_recommended' => true,
                'is_rush_hour_offer' => false,
            ],
            [
                'category_slug' => 'desserts-sweetners',
                'name' => 'Honey Jar',
                'description' => 'Pure natural honey jar.',
                'image_url' => 'products/honey-jar.jpg',
                'price' => 6000.00,
                'stock' => 45,
                'discount' => 350.00,
                'is_recommended' => false,
                'is_rush_hour_offer' => true,
                'rush_hour_starts_at' => now()->subMinutes(45),
                'rush_hour_ends_at' => now()->addHours(1),
            ],
        ];

        foreach ($products as $product) {
            $category = $categories->get($product['category_slug']);

            if (! $category) {
                continue;
            }

            Product::query()->updateOrCreate(
                ['slug' => str()->slug($product['name'])],
                [
                    'category_id' => $category->id,
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'image_url' => $product['image_url'],
                    'price' => $product['price'],
                    'stock' => $product['stock'],
                    'discount' => $product['discount'],
                    'is_active' => true,
                    'is_recommended' => $product['is_recommended'],
                    'is_rush_hour_offer' => $product['is_rush_hour_offer'],
                    'rush_hour_starts_at' => $product['rush_hour_starts_at'] ?? null,
                    'rush_hour_ends_at' => $product['rush_hour_ends_at'] ?? null,
                ]
            );
        }
    }
}
