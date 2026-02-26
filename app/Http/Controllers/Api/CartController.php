<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutService $checkoutService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $summary = $this->cartService->summary($request->user());

        return response()->json([
            'message' => 'Cart fetched successfully.',
            'data' => $this->formatSummary($summary),
        ]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);

        if (! $product->is_active) {
            return response()->json([
                'message' => 'This product is currently unavailable.',
            ], 422);
        }

        $quantityToAdd = (int) ($validated['quantity'] ?? 1);
        $existing = $request->user()->cartItems()->where('product_id', $product->id)->first();
        $newQuantity = $quantityToAdd + (int) ($existing?->quantity ?? 0);

        if ($newQuantity > (int) $product->stock) {
            return response()->json([
                'message' => 'Requested quantity exceeds available stock.',
            ], 422);
        }

        $this->checkoutService->addOrUpdateCartItem($request->user(), $product, $newQuantity);

        return response()->json([
            'message' => 'Product added to cart successfully.',
            'data' => $this->formatSummary($this->cartService->summary($request->user())),
        ], 201);
    }

    public function updateItem(Request $request, CartItem $cartItem): JsonResponse
    {
        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = $cartItem->product;

        if (! $product || ! $product->is_active) {
            return response()->json([
                'message' => 'This product is currently unavailable.',
            ], 422);
        }

        if ((int) $validated['quantity'] > (int) $product->stock) {
            return response()->json([
                'message' => 'Requested quantity exceeds available stock.',
            ], 422);
        }

        $this->checkoutService->addOrUpdateCartItem($request->user(), $product, (int) $validated['quantity']);

        return response()->json([
            'message' => 'Cart item quantity updated successfully.',
            'data' => $this->formatSummary($this->cartService->summary($request->user())),
        ]);
    }

    public function removeItem(Request $request, CartItem $cartItem): JsonResponse
    {
        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $cartItem->delete();

        return response()->json([
            'message' => 'Cart item removed successfully.',
            'data' => $this->formatSummary($this->cartService->summary($request->user())),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->user()->cartItems()->delete();

        return response()->json([
            'message' => 'Cart cleared successfully.',
            'data' => $this->formatSummary($this->cartService->summary($request->user())),
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function formatSummary(array $summary): array
    {
        return [
            'items' => $summary['items'],
            'item_count' => $summary['item_count'],
            'subtotal' => $summary['subtotal'],
            'total_discount' => $summary['total_discount'],
            'total' => $summary['total'],
        ];
    }
}
