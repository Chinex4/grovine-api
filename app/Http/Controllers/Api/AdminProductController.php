<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->with(['category:id,name,slug'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Products fetched successfully.',
            'data' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'image' => ['required', 'image', 'max:5120'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'stock' => ['required', 'integer', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0', 'lte:price'],
            'is_active' => ['sometimes', 'boolean'],
            'is_recommended' => ['sometimes', 'boolean'],
            'is_rush_hour_offer' => ['sometimes', 'boolean'],
            'rush_hour_starts_at' => ['nullable', 'date'],
            'rush_hour_ends_at' => ['nullable', 'date', 'after_or_equal:rush_hour_starts_at'],
        ]);

        $path = $request->file('image')->store('products', 'public');

        $product = Product::query()->create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $this->makeUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'image_url' => $path,
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'discount' => $validated['discount'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'is_recommended' => $validated['is_recommended'] ?? false,
            'is_rush_hour_offer' => $validated['is_rush_hour_offer'] ?? false,
            'rush_hour_starts_at' => $validated['rush_hour_starts_at'] ?? null,
            'rush_hour_ends_at' => $validated['rush_hour_ends_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product->load('category:id,name,slug'),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image' => ['sometimes', 'image', 'max:5120'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'category_id' => ['sometimes', 'uuid', 'exists:categories,id'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_recommended' => ['sometimes', 'boolean'],
            'is_rush_hour_offer' => ['sometimes', 'boolean'],
            'rush_hour_starts_at' => ['sometimes', 'nullable', 'date'],
            'rush_hour_ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:rush_hour_starts_at'],
        ]);

        $resolvedPrice = (float) ($validated['price'] ?? $product->getRawOriginal('price'));
        $resolvedDiscount = (float) ($validated['discount'] ?? $product->getRawOriginal('discount'));

        if ($resolvedDiscount > $resolvedPrice) {
            return response()->json([
                'message' => 'Discount cannot be greater than price.',
            ], 422);
        }

        if (array_key_exists('name', $validated)) {
            $validated['slug'] = $this->makeUniqueSlug($validated['name'], $product->id);
        }

        if ($request->hasFile('image')) {
            $oldPath = $product->getRawOriginal('image_url');

            if ($oldPath && ! str_starts_with($oldPath, 'http://') && ! str_starts_with($oldPath, 'https://')) {
                Storage::disk('public')->delete($oldPath);
            }

            $validated['image_url'] = $request->file('image')->store('products', 'public');
        }

        unset($validated['image']);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $product->fresh()->load('category:id,name,slug'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $path = $product->getRawOriginal('image_url');

        if ($path && ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://')) {
            Storage::disk('public')->delete($path);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    private function makeUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'product';
        $slug = $base;
        $counter = 2;

        while (
            Product::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
