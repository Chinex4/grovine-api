<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RecipeIngredient
 */
class RecipeIngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_text' => $this->item_text,
            'cart_quantity' => $this->cart_quantity,
            'is_optional' => $this->is_optional,
            'sort_order' => $this->sort_order,
            'product' => $this->whenLoaded('product', function (): ?array {
                if (! $this->product) {
                    return null;
                }

                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'image_url' => $this->product->image_url,
                    'price' => $this->product->price,
                    'discount' => $this->product->discount,
                    'final_price' => $this->product->final_price,
                    'stock' => $this->product->stock,
                    'is_active' => $this->product->is_active,
                ];
            }),
        ];
    }
}

