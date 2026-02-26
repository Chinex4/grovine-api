<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralPayout extends Model
{
    use HasUuids;

    public const MILESTONE_REFERRER_FIRST_ORDER = 'REFERRER_FIRST_ORDER';
    public const MILESTONE_REFERRER_SECOND_ORDER = 'REFERRER_SECOND_ORDER';
    public const MILESTONE_REFERRED_FIRST_ORDER = 'REFERRED_FIRST_ORDER';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'beneficiary_user_id',
        'order_id',
        'milestone',
        'amount',
        'currency',
        'credited_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credited_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

