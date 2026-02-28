<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
        ]);

        $query = $this->basePublicQuery();

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        $products = $query
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Products fetched successfully.',
            'data' => $products,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
        ]);

        $searchTerm = trim($validated['q']);

        $query = $this->basePublicQuery();

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        $products = $query
            ->where(function (Builder $inner) use ($searchTerm): void {
                $inner->where('products.name', 'like', "%{$searchTerm}%")
                    ->orWhere('products.description', 'like', "%{$searchTerm}%")
                    ->orWhereHas('category', function (Builder $categoryQuery) use ($searchTerm): void {
                        $categoryQuery->where('name', 'like', "%{$searchTerm}%");
                    });
            })
            ->orderByDesc('is_recommended')
            ->orderBy('products.name')
            ->get();

        return response()->json([
            'message' => 'Search results fetched successfully.',
            'data' => $products,
        ]);
    }

    public function recommended(): JsonResponse
    {
        $products = $this->basePublicQuery()
            ->where('is_recommended', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Recommended products fetched successfully.',
            'data' => $products,
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        $product->loadMissing('category:id,name,slug,is_active');

        if (! $product->is_active || ! $product->category || ! $product->category->is_active) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Product detail fetched successfully.',
            'data' => $product,
        ]);
    }

    public function rushHourOffers(): JsonResponse
    {
        $now = now();

        $products = $this->basePublicQuery()
            ->where('is_rush_hour_offer', true)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $noWindow): void {
                    $noWindow->whereNull('rush_hour_starts_at')
                        ->whereNull('rush_hour_ends_at');
                })->orWhere(function (Builder $window) use ($now): void {
                    $window->where(function (Builder $starts) use ($now): void {
                        $starts->whereNull('rush_hour_starts_at')
                            ->orWhere('rush_hour_starts_at', '<=', $now);
                    })->where(function (Builder $ends) use ($now): void {
                        $ends->whereNull('rush_hour_ends_at')
                            ->orWhere('rush_hour_ends_at', '>=', $now);
                    });
                });
            })
            ->orderByDesc('discount')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Rush hour offers fetched successfully.',
            'data' => $products,
        ]);
    }

    private function basePublicQuery(): Builder
    {
        return Product::query()
            ->with(['category:id,name,slug'])
            ->where('is_active', true)
            ->whereHas('category', function (Builder $query): void {
                $query->where('is_active', true);
            });
    }
}
