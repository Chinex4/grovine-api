<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_CHEF = 'chef';

    public const ROLE_ADMIN = 'admin';

    public const ACCOUNT_STATUS_ACTIVE = 'active';

    public const ACCOUNT_STATUS_SUSPENDED = 'suspended';

    public const ACCOUNT_STATUS_BANNED = 'banned';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'chef_name',
        'username',
        'email',
        'phone',
        'date_of_birth',
        'address',
        'referral_code',
        'referred_by_user_id',
        'role',
        'email_verified_at',
        'account_status',
        'suspended_until',
        'suspension_reason',
        'warning_count',
        'last_warned_at',
        'banned_at',
        'banned_reason',
        'last_seen_at',
        'chef_niche_id',
        'profile_picture',
        'wallet_balance',
        'onboarding_completed',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'suspended_until' => 'datetime',
            'warning_count' => 'integer',
            'last_warned_at' => 'datetime',
            'banned_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'wallet_balance' => 'decimal:2',
            'onboarding_completed' => 'boolean',
        ];
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function getProfilePictureAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return url(Storage::url($value));
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    public function dailyActivities(): HasMany
    {
        return $this->hasMany(UserDailyActivity::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function chefNiche(): BelongsTo
    {
        return $this->belongsTo(ChefNiche::class, 'chef_niche_id');
    }

    public function chefNiches(): BelongsToMany
    {
        return $this->belongsToMany(ChefNiche::class, 'chef_niche_user')
            ->withTimestamps();
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'chef_id');
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function walletPaymentTransactions(): HasMany
    {
        return $this->hasMany(WalletPaymentTransaction::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function referralPayoutsAsReferrer(): HasMany
    {
        return $this->hasMany(ReferralPayout::class, 'referrer_user_id');
    }

    public function referralPayoutsAsBeneficiary(): HasMany
    {
        return $this->hasMany(ReferralPayout::class, 'beneficiary_user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(UserDeviceToken::class);
    }

    public function notificationDispatchLogs(): HasMany
    {
        return $this->hasMany(NotificationDispatchLog::class);
    }

    public function bookmarkedRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_bookmarks')
            ->withTimestamps();
    }

    public function favoriteFoods(): BelongsToMany
    {
        return $this->belongsToMany(FavoriteFood::class, 'favorite_food_user')
            ->withTimestamps();
    }

    public function cuisineRegions(): BelongsToMany
    {
        return $this->belongsToMany(CuisineRegion::class, 'cuisine_region_user')
            ->withTimestamps();
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorite_product_user')
            ->withTimestamps();
    }

    public function effectiveAccountStatus(): string
    {
        if ($this->account_status === self::ACCOUNT_STATUS_BANNED || $this->banned_at) {
            return self::ACCOUNT_STATUS_BANNED;
        }

        if ($this->account_status === self::ACCOUNT_STATUS_SUSPENDED) {
            if ($this->suspended_until === null || $this->suspended_until->isFuture()) {
                return self::ACCOUNT_STATUS_SUSPENDED;
            }
        }

        return self::ACCOUNT_STATUS_ACTIVE;
    }

    public function normalizeAccountStatus(): void
    {
        if (
            $this->account_status === self::ACCOUNT_STATUS_SUSPENDED
            && $this->suspended_until !== null
            && $this->suspended_until->isPast()
        ) {
            $this->forceFill([
                'account_status' => self::ACCOUNT_STATUS_ACTIVE,
                'suspended_until' => null,
                'suspension_reason' => null,
            ])->saveQuietly();

            $this->refresh();
        }
    }

    public function isBlockedFromAccess(): bool
    {
        return in_array($this->effectiveAccountStatus(), [
            self::ACCOUNT_STATUS_SUSPENDED,
            self::ACCOUNT_STATUS_BANNED,
        ], true);
    }
}
