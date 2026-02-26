<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ReferralPayout;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function ensureUserReferralCode(User $user): string
    {
        $current = strtoupper(trim((string) $user->referral_code));

        if ($current !== '' && ! User::query()
            ->where('referral_code', $current)
            ->where('id', '!=', $user->id)
            ->exists()) {
            if ($current !== $user->referral_code) {
                $user->update(['referral_code' => $current]);
            }

            return $current;
        }

        $code = $this->generateUniqueCode($user->name);
        $user->update(['referral_code' => $code]);

        return $code;
    }

    public function findReferrerByCode(?string $code, ?string $excludeUserId = null): ?User
    {
        $normalized = strtoupper(trim((string) $code));

        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->where('referral_code', $normalized)
            ->when($excludeUserId, static fn ($query, $id) => $query->where('id', '!=', $id))
            ->first();
    }

    public function applyRewardsForPaidOrder(Order $order): void
    {
        $order->loadMissing(['user.referredBy']);
        $referredUser = $order->user;

        if (! $referredUser instanceof User || ! is_string($referredUser->referred_by_user_id)) {
            return;
        }

        $referrer = $referredUser->referredBy;

        if (! $referrer instanceof User || $referrer->id === $referredUser->id) {
            return;
        }

        $paidOrderCount = Order::query()
            ->where('user_id', $referredUser->id)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->count();

        if ($paidOrderCount < 1) {
            return;
        }

        if ($paidOrderCount >= 1) {
            $this->awardMilestone(
                referredUser: $referredUser,
                referrer: $referrer,
                beneficiary: $referredUser,
                milestone: ReferralPayout::MILESTONE_REFERRED_FIRST_ORDER,
                amount: (float) config('referral.referred_first_order_reward', 500),
                order: $order,
                description: 'Referral reward for completing your first order.',
            );

            $this->awardMilestone(
                referredUser: $referredUser,
                referrer: $referrer,
                beneficiary: $referrer,
                milestone: ReferralPayout::MILESTONE_REFERRER_FIRST_ORDER,
                amount: (float) config('referral.referrer_first_order_reward', 500),
                order: $order,
                description: 'Referral reward for a friend completing their first order.',
            );
        }

        if ($paidOrderCount >= 2) {
            $this->awardMilestone(
                referredUser: $referredUser,
                referrer: $referrer,
                beneficiary: $referrer,
                milestone: ReferralPayout::MILESTONE_REFERRER_SECOND_ORDER,
                amount: (float) config('referral.referrer_second_order_reward', 500),
                order: $order,
                description: 'Referral reward for a friend completing their second order.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $referralCode = $this->ensureUserReferralCode($user);

        /** @var Collection<int, User> $referredUsers */
        $referredUsers = User::query()
            ->where('referred_by_user_id', $user->id)
            ->withCount([
                'orders as paid_orders_count' => static fn ($query) => $query->where('payment_status', Order::PAYMENT_PAID),
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'username', 'email', 'profile_picture', 'created_at']);

        $referrerBonusByReferred = ReferralPayout::query()
            ->selectRaw('referred_user_id, COALESCE(SUM(amount), 0) as total_amount')
            ->where('referrer_user_id', $user->id)
            ->whereIn('milestone', [
                ReferralPayout::MILESTONE_REFERRER_FIRST_ORDER,
                ReferralPayout::MILESTONE_REFERRER_SECOND_ORDER,
            ])
            ->groupBy('referred_user_id')
            ->pluck('total_amount', 'referred_user_id');

        $friendBonusByReferred = ReferralPayout::query()
            ->selectRaw('referred_user_id, COALESCE(SUM(amount), 0) as total_amount')
            ->whereColumn('beneficiary_user_id', 'referred_user_id')
            ->where('milestone', ReferralPayout::MILESTONE_REFERRED_FIRST_ORDER)
            ->groupBy('referred_user_id')
            ->pluck('total_amount', 'referred_user_id');

        $totalReferrerBonus = (float) ReferralPayout::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('milestone', [
                ReferralPayout::MILESTONE_REFERRER_FIRST_ORDER,
                ReferralPayout::MILESTONE_REFERRER_SECOND_ORDER,
            ])
            ->sum('amount');

        $firstOrderConversions = $referredUsers->filter(static fn (User $referred): bool => (int) ($referred->paid_orders_count ?? 0) >= 1)->count();
        $secondOrderConversions = $referredUsers->filter(static fn (User $referred): bool => (int) ($referred->paid_orders_count ?? 0) >= 2)->count();

        return [
            'referral_code' => $referralCode,
            'currency' => (string) config('referral.currency', 'NGN'),
            'reward_rules' => [
                'referrer_first_order_reward' => number_format((float) config('referral.referrer_first_order_reward', 500), 2, '.', ''),
                'referrer_second_order_reward' => number_format((float) config('referral.referrer_second_order_reward', 500), 2, '.', ''),
                'referred_first_order_reward' => number_format((float) config('referral.referred_first_order_reward', 500), 2, '.', ''),
            ],
            'stats' => [
                'total_referrals' => $referredUsers->count(),
                'first_order_conversions' => $firstOrderConversions,
                'second_order_conversions' => $secondOrderConversions,
                'pending_referrals' => max($referredUsers->count() - $firstOrderConversions, 0),
                'total_referrer_bonus_earned' => number_format($totalReferrerBonus, 2, '.', ''),
            ],
            'referred_users' => $referredUsers->map(static function (User $referred) use ($referrerBonusByReferred, $friendBonusByReferred): array {
                $paidOrdersCount = (int) ($referred->paid_orders_count ?? 0);

                return [
                    'id' => $referred->id,
                    'name' => $referred->name,
                    'username' => $referred->username,
                    'email' => $referred->email,
                    'profile_picture' => $referred->profile_picture,
                    'paid_orders_count' => $paidOrdersCount,
                    'first_order_completed' => $paidOrdersCount >= 1,
                    'second_order_completed' => $paidOrdersCount >= 2,
                    'referrer_bonus_earned' => number_format((float) ($referrerBonusByReferred[$referred->id] ?? 0), 2, '.', ''),
                    'friend_bonus_earned' => number_format((float) ($friendBonusByReferred[$referred->id] ?? 0), 2, '.', ''),
                    'joined_at' => $referred->created_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    private function generateUniqueCode(string $name): string
    {
        $prefix = strtoupper(Str::of($name)->replaceMatches('/[^A-Za-z0-9]/', '')->substr(0, 4)->value());
        $prefix = $prefix !== '' ? $prefix : 'GRV';

        do {
            $code = $prefix.Str::upper(Str::random(5));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    private function awardMilestone(
        User $referredUser,
        User $referrer,
        User $beneficiary,
        string $milestone,
        float $amount,
        Order $order,
        string $description,
    ): void {
        if ($amount <= 0) {
            return;
        }

        $didAward = false;

        DB::transaction(function () use ($referredUser, $referrer, $beneficiary, $milestone, $amount, $order, $description, &$didAward): void {
            $existing = ReferralPayout::query()
                ->where('referred_user_id', $referredUser->id)
                ->where('milestone', $milestone)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return;
            }

            $lockedBeneficiary = User::query()->whereKey($beneficiary->id)->lockForUpdate()->first();

            if (! $lockedBeneficiary instanceof User) {
                return;
            }

            $before = (float) $lockedBeneficiary->wallet_balance;
            $after = $before + $amount;

            $lockedBeneficiary->update([
                'wallet_balance' => $after,
            ]);

            $payout = ReferralPayout::query()->create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $referredUser->id,
                'beneficiary_user_id' => $lockedBeneficiary->id,
                'order_id' => $order->id,
                'milestone' => $milestone,
                'amount' => $amount,
                'currency' => (string) config('referral.currency', 'NGN'),
                'credited_at' => now(),
                'metadata' => [
                    'order_number' => $order->order_number,
                ],
            ]);

            WalletTransaction::query()->create([
                'user_id' => $lockedBeneficiary->id,
                'type' => WalletTransaction::TYPE_REFERRAL_BONUS,
                'direction' => WalletTransaction::DIRECTION_CREDIT,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => WalletTransaction::STATUS_SUCCESS,
                'reference' => $order->order_number,
                'description' => $description,
                'metadata' => [
                    'referral_payout_id' => $payout->id,
                    'referred_user_id' => $referredUser->id,
                    'milestone' => $milestone,
                ],
            ]);

            $didAward = true;
        });

        if (! $didAward) {
            return;
        }

        $freshBeneficiary = User::query()->whereKey($beneficiary->id)->first();

        if ($freshBeneficiary instanceof User) {
            $this->notificationService->sendAccountActivity(
                user: $freshBeneficiary,
                title: 'Referral reward credited',
                message: 'You received NGN '.number_format($amount, 2, '.', '').' referral reward.',
                data: [
                    'milestone' => $milestone,
                    'referred_user_id' => $referredUser->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'amount' => number_format($amount, 2, '.', ''),
                ],
                channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
            );
        }
    }
}
