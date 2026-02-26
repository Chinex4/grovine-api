<?php

namespace Database\Seeders;

use App\Models\ChefNiche;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RecipeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $chefNiches = ChefNiche::query()->get()->keyBy('slug');
        $products = Product::query()->get()->keyBy('slug');

        $chefs = [
            'richard.nduh@grovine.ng' => User::query()->updateOrCreate(
                ['email' => 'richard.nduh@grovine.ng'],
                [
                    'name' => 'Richard Nduh',
                    'chef_name' => 'Chef Richard Nduh',
                    'username' => 'chef_richard_nduh',
                    'role' => User::ROLE_CHEF,
                    'chef_niche_id' => $chefNiches->get('soups-stews')?->id,
                    'email_verified_at' => now(),
                    'onboarding_completed' => true,
                ]
            ),
            'adaeze.kitchen@grovine.ng' => User::query()->updateOrCreate(
                ['email' => 'adaeze.kitchen@grovine.ng'],
                [
                    'name' => 'Adaeze Nwosu',
                    'chef_name' => 'Chef Adaeze',
                    'username' => 'chef_adaeze',
                    'role' => User::ROLE_CHEF,
                    'chef_niche_id' => $chefNiches->get('vegan-vegetarian')?->id,
                    'email_verified_at' => now(),
                    'onboarding_completed' => true,
                ]
            ),
        ];

        $recipes = [
            [
                'chef_email' => 'richard.nduh@grovine.ng',
                'title' => 'Egusi Soup With Chicken',
                'short_description' => 'Rich Nigerian egusi soup cooked with tender chicken and leafy vegetables.',
                'instructions' => "Prepare the base:\nBlend pepper mix and cook in palm oil.\n\nAdd protein:\nAdd chicken stock and chicken pieces, then simmer.\n\nBuild flavor:\nStir in ground egusi and crayfish until thick.\n\nFinish:\nAdd vegetables and cook for 5 minutes before serving hot.",
                'video_url' => 'recipes/videos/egusi-soup-with-chicken.mp4',
                'cover_image_url' => 'recipes/covers/egusi-soup-with-chicken.jpg',
                'duration_seconds' => 909,
                'servings' => 4,
                'estimated_cost' => 20000,
                'is_recommended' => true,
                'is_quick_recipe' => false,
                'views_count' => 1840,
                'ingredients' => [
                    ['item_text' => 'Chicken breast cuts', 'product_slug' => 'chicken-breast', 'cart_quantity' => 1],
                    ['item_text' => 'Carrot garnish', 'product_slug' => 'carrot-pack', 'cart_quantity' => 1, 'is_optional' => true],
                    ['item_text' => 'Seasoning and spices', 'product_slug' => null, 'cart_quantity' => 1],
                ],
            ],
            [
                'chef_email' => 'adaeze.kitchen@grovine.ng',
                'title' => 'Egg Shakshuka',
                'short_description' => 'A hearty pan recipe with eggs poached in a rich spiced tomato sauce.',
                'instructions' => "Step 1:\nSaute onions and peppers in olive oil.\n\nStep 2:\nAdd tomatoes and spices, then simmer to thicken.\n\nStep 3:\nMake small wells and crack eggs into the sauce.\n\nStep 4:\nCover and cook gently until eggs are set.",
                'video_url' => 'recipes/videos/egg-shakshuka.mp4',
                'cover_image_url' => 'recipes/covers/egg-shakshuka.jpg',
                'duration_seconds' => 540,
                'servings' => 2,
                'estimated_cost' => 12000,
                'is_recommended' => true,
                'is_quick_recipe' => true,
                'views_count' => 2300,
                'ingredients' => [
                    ['item_text' => 'Olive oil', 'product_slug' => 'honey-jar', 'cart_quantity' => 1, 'is_optional' => true],
                    ['item_text' => 'Fresh tomatoes and peppers', 'product_slug' => 'carrot-pack', 'cart_quantity' => 1],
                    ['item_text' => 'Eggs', 'product_slug' => null, 'cart_quantity' => 1],
                ],
            ],
            [
                'chef_email' => 'adaeze.kitchen@grovine.ng',
                'title' => 'Quick Fruit Bowl',
                'short_description' => 'A fast fresh fruit bowl perfect for breakfast or a midday snack.',
                'instructions' => "Chop grapes, apples, pineapple and kiwi.\nToss gently in a bowl.\nAdd a drizzle of honey and chill for 5 minutes before serving.",
                'video_url' => 'recipes/videos/quick-fruit-bowl.mp4',
                'cover_image_url' => 'recipes/covers/quick-fruit-bowl.jpg',
                'duration_seconds' => 360,
                'servings' => 1,
                'estimated_cost' => 8000,
                'is_recommended' => false,
                'is_quick_recipe' => true,
                'views_count' => 980,
                'ingredients' => [
                    ['item_text' => 'Grapes', 'product_slug' => 'grape', 'cart_quantity' => 1],
                    ['item_text' => 'Apple', 'product_slug' => 'apple', 'cart_quantity' => 1],
                    ['item_text' => 'Pineapple', 'product_slug' => 'pineapple', 'cart_quantity' => 1],
                    ['item_text' => 'Kiwi', 'product_slug' => 'kiwi', 'cart_quantity' => 1],
                ],
            ],
            [
                'chef_email' => 'richard.nduh@grovine.ng',
                'title' => 'Chicken Pepper Soup',
                'short_description' => 'Comforting spicy chicken pepper soup with aromatic herbs.',
                'instructions' => "Boil chicken with onions and seasoning.\nAdd pepper soup spice mix and simmer.\nAdjust heat level and serve warm.",
                'video_url' => 'recipes/videos/chicken-pepper-soup.mp4',
                'cover_image_url' => 'recipes/covers/chicken-pepper-soup.jpg',
                'duration_seconds' => 720,
                'servings' => 3,
                'estimated_cost' => 15000,
                'is_recommended' => true,
                'is_quick_recipe' => false,
                'views_count' => 1420,
                'ingredients' => [
                    ['item_text' => 'Chicken breast', 'product_slug' => 'chicken-breast', 'cart_quantity' => 1],
                    ['item_text' => 'Fresh vegetables', 'product_slug' => 'carrot-pack', 'cart_quantity' => 1, 'is_optional' => true],
                ],
            ],
            [
                'chef_email' => 'adaeze.kitchen@grovine.ng',
                'title' => 'Pineapple Oats Smoothie',
                'short_description' => 'A quick smoothie made with oats, pineapple and citrus notes.',
                'instructions' => "Blend oats, pineapple chunks and orange juice.\nAdd ice and blend until smooth.\nServe chilled.",
                'video_url' => 'recipes/videos/pineapple-oats-smoothie.mp4',
                'cover_image_url' => 'recipes/covers/pineapple-oats-smoothie.jpg',
                'duration_seconds' => 300,
                'servings' => 1,
                'estimated_cost' => 7000,
                'is_recommended' => false,
                'is_quick_recipe' => true,
                'views_count' => 760,
                'ingredients' => [
                    ['item_text' => 'Quaker oats', 'product_slug' => 'quaker-oats', 'cart_quantity' => 1],
                    ['item_text' => 'Pineapple chunks', 'product_slug' => 'pineapple', 'cart_quantity' => 1],
                    ['item_text' => 'Orange juice', 'product_slug' => 'orange-juice', 'cart_quantity' => 1],
                ],
            ],
            [
                'chef_email' => 'richard.nduh@grovine.ng',
                'title' => 'Honey Glazed Carrots',
                'short_description' => 'Pan-seared carrots finished with a light honey glaze.',
                'instructions' => "Saute sliced carrots in a little oil.\nAdd honey glaze and a pinch of salt.\nCook until glossy and tender.",
                'video_url' => 'recipes/videos/honey-glazed-carrots.mp4',
                'cover_image_url' => 'recipes/covers/honey-glazed-carrots.jpg',
                'duration_seconds' => 480,
                'servings' => 2,
                'estimated_cost' => 6500,
                'is_recommended' => false,
                'is_quick_recipe' => true,
                'views_count' => 610,
                'ingredients' => [
                    ['item_text' => 'Carrot pack', 'product_slug' => 'carrot-pack', 'cart_quantity' => 1],
                    ['item_text' => 'Honey', 'product_slug' => 'honey-jar', 'cart_quantity' => 1],
                ],
            ],
        ];

        foreach ($recipes as $item) {
            $chef = $chefs[$item['chef_email']] ?? null;

            if (! $chef) {
                continue;
            }

            $slug = Str::slug($item['title']);

            $recipe = Recipe::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'chef_id' => $chef->id,
                    'status' => Recipe::STATUS_APPROVED,
                    'title' => $item['title'],
                    'short_description' => $item['short_description'],
                    'instructions' => $item['instructions'],
                    'video_url' => $item['video_url'],
                    'cover_image_url' => $item['cover_image_url'],
                    'duration_seconds' => $item['duration_seconds'],
                    'servings' => $item['servings'],
                    'estimated_cost' => $item['estimated_cost'],
                    'is_recommended' => $item['is_recommended'],
                    'is_quick_recipe' => $item['is_quick_recipe'],
                    'views_count' => $item['views_count'],
                    'submitted_at' => now()->subDays(5),
                    'approved_at' => now()->subDays(4),
                    'published_at' => now()->subDays(4),
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]
            );

            $recipe->ingredients()->delete();

            foreach ($item['ingredients'] as $index => $ingredient) {
                $productSlug = $ingredient['product_slug'] ?? null;
                $productId = $productSlug ? $products->get($productSlug)?->id : null;

                $recipe->ingredients()->create([
                    'item_text' => $ingredient['item_text'],
                    'product_id' => $productId,
                    'cart_quantity' => (int) ($ingredient['cart_quantity'] ?? 1),
                    'is_optional' => (bool) ($ingredient['is_optional'] ?? false),
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }
}
