<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasUuids;

    public const STATUS_AWAITING_PAYMENT = 'AWAITING_PAYMENT';
    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_CANCELED = 'CANCELED';

    public const PAYMENT_PENDING = 'PENDING';
    public const PAYMENT_PAID = 'PAID';
    public const PAYMENT_FAILED = 'FAILED';

    public const PAYMENT_METHOD_PAYSTACK = 'paystack';
    public const PAYMENT_METHOD_WALLET = 'wallet';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'order_number',
        'user_id',
        'status',
        'payment_method',
        'payment_status',
        'subtotal',
        'delivery_fee',
        'service_fee',
        'affiliate_fee',
        'total',
        'currency',
        'delivery_address',
        'rider_note',
        'delivery_type',
        'scheduled_for',
        'paid_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'affiliate_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'scheduled_for' => 'datetime',
            'paid_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function latestPaystackPayment(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class)
            ->where('gateway', 'paystack')
            ->where('payment_method', self::PAYMENT_METHOD_PAYSTACK)
            ->latestOfMany();
    }

    public function bucket(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'completed',
            self::STATUS_CANCELED => 'canceled',
            default => 'ongoing',
        };
    }
}
