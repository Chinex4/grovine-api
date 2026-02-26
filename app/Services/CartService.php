<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\User;

class CartService
{
    /**
     * @return array{
     *     items:\Illuminate\Support\Collection<int, CartItem>,
     *     item_count:int,
     *     subtotal:string,
     *     total_discount:string,
     *     total:string
     * }
     */
    public function summary(User $user): array
    {
        $items = $user->cartItems()
            ->with(['product:id,name,image_url,price,discount,stock,is_active'])
            ->orderByDesc('created_at')
            ->get();

        $itemCount = 0;
        $subtotal = 0.0;
        $totalDiscount = 0.0;

        foreach ($items as $item) {
            $qty = max((int) $item->quantity, 0);
            $itemCount += $qty;

            $unitPrice = (float) $item->getRawOriginal('unit_price');
            $unitDiscount = (float) $item->getRawOriginal('unit_discount');

            $subtotal += ($unitPrice * $qty);
            $totalDiscount += ($unitDiscount * $qty);
        }

        $total = max($subtotal - $totalDiscount, 0);

        return [
            'items' => $items,
            'item_count' => $itemCount,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total_discount' => number_format($totalDiscount, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }
}
