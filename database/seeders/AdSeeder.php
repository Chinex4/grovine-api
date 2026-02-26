<?php

namespace Database\Seeders;

use App\Models\Ad;
use Illuminate\Database\Seeder;

class AdSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $ads = [
            [
                'title' => '50% Discount on Vegetables',
                'image_url' => 'ads/vegetable-discount.jpg',
                'link' => 'https://grovine.ng/shop?category=vegetables',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'title' => 'Fresh Fruit Basket Promo',
                'image_url' => 'ads/fruit-basket-promo.jpg',
                'link' => 'https://grovine.ng/shop?category=fruits',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'title' => 'Rush Hour Grocery Offers',
                'image_url' => 'ads/rush-hour-offers.jpg',
                'link' => 'https://grovine.ng/offers/rush-hour',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($ads as $ad) {
            Ad::query()->updateOrCreate(
                ['title' => $ad['title']],
                [
                    'image_url' => $ad['image_url'],
                    'link' => $ad['link'],
                    'sort_order' => $ad['sort_order'],
                    'is_active' => $ad['is_active'],
                ]
            );
        }
    }
}
