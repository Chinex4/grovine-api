<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'image_url',
        'price',
        'stock',
        'discount',
        'is_active',
        'is_recommended',
        'is_rush_hour_offer',
        'rush_hour_starts_at',
        'rush_hour_ends_at',
    ];

    protected $appends = [
        'final_price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_recommended' => 'boolean',
            'is_rush_hour_offer' => 'boolean',
            'rush_hour_starts_at' => 'datetime',
            'rush_hour_ends_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorite_product_user')
            ->withTimestamps();
    }

    public function getImageUrlAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return url(Storage::url($value));
    }

    public function getFinalPriceAttribute(): string
    {
        $final = (float) $this->getRawOriginal('price') - (float) $this->getRawOriginal('discount');

        return number_format(max($final, 0), 2, '.', '');
    }
}
