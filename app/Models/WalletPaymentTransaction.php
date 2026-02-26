<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletPaymentTransaction extends Model
{
    use HasUuids;

    public const DIRECTION_DEPOSIT = 'DEPOSIT';
    public const DIRECTION_WITHDRAWAL = 'WITHDRAWAL';

    public const STATUS_INITIALIZED = 'INITIALIZED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'direction',
        'gateway',
        'idempotency_key',
        'reference',
        'amount',
        'currency',
        'status',
        'recipient_code',
        'bank_code',
        'account_number',
        'account_name',
        'last_webhook_event',
        'processed_at',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
