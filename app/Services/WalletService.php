<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletPaymentTransaction;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WalletService
{
    public function __construct(
        private readonly PaystackService $paystackService,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function initializeDeposit(User $user, float $amount, string $idempotencyKey): array
    {
        $this->assertMinimumDeposit($amount);

        $existing = WalletPaymentTransaction::query()
            ->where('user_id', $user->id)
            ->where('direction', WalletPaymentTransaction::DIRECTION_DEPOSIT)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'reused' => true,
                'transaction' => $existing,
                'payment_data' => $existing->gateway_response['data'] ?? null,
            ];
        }

        $transaction = WalletPaymentTransaction::query()->create([
            'user_id' => $user->id,
            'direction' => WalletPaymentTransaction::DIRECTION_DEPOSIT,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'currency' => (string) config('wallet.default_currency', 'NGN'),
            'status' => WalletPaymentTransaction::STATUS_INITIALIZED,
        ]);

        $reference = $this->generateReference('WDP');

        try {
            $paystackResponse = $this->paystackService->initializeTransaction(
                email: $user->email,
                reference: $reference,
                amount: $amount,
                metadata: [
                    'wallet_payment_transaction_id' => $transaction->id,
                    'user_id' => $user->id,
                    'type' => 'wallet_deposit',
                ],
            );

            $transaction->update([
                'reference' => $reference,
                'gateway_response' => $paystackResponse,
                'status' => WalletPaymentTransaction::STATUS_INITIALIZED,
            ]);

            return [
                'reused' => false,
                'transaction' => $transaction->fresh(),
                'payment_data' => $paystackResponse['data'] ?? null,
            ];
        } catch (RuntimeException $exception) {
            $transaction->update([
                'reference' => $reference,
                'status' => WalletPaymentTransaction::STATUS_FAILED,
                'gateway_response' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyDeposit(User $user, string $reference): array
    {
        $transaction = WalletPaymentTransaction::query()
            ->where('user_id', $user->id)
            ->where('direction', WalletPaymentTransaction::DIRECTION_DEPOSIT)
            ->where('reference', $reference)
            ->first();

        if (! $transaction) {
            throw new RuntimeException('Deposit reference not found.');
        }

        $response = $this->paystackService->verifyTransaction($reference);
        $status = strtolower((string) ($response['data']['status'] ?? ''));

        if ($status === 'success') {
            $this->applySuccessfulDeposit($transaction, $response, 'manual_verify');
        } else {
            $this->applyFailedDeposit($transaction, $response, 'manual_verify');
        }

        return [
            'transaction' => $transaction->fresh(),
            'wallet_balance' => (string) $user->fresh()->wallet_balance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listNigerianBanks(): array
    {
        $response = $this->paystackService->listBanksNigeria();

        return [
            'banks' => $response['data'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyNigerianBankAccount(string $bankCode, string $accountNumber): array
    {
        $response = $this->paystackService->resolveBankAccount($accountNumber, $bankCode);

        return [
            'account_name' => $response['data']['account_name'] ?? null,
            'account_number' => $response['data']['account_number'] ?? null,
            'bank_code' => $bankCode,
            'raw' => $response['data'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdraw(
        User $user,
        float $amount,
        string $idempotencyKey,
        string $bankCode,
        string $accountNumber,
        string $accountName,
        ?string $reason = null,
    ): array {
        $this->assertMinimumWithdrawal($amount);

        $existing = WalletPaymentTransaction::query()
            ->where('user_id', $user->id)
            ->where('direction', WalletPaymentTransaction::DIRECTION_WITHDRAWAL)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'reused' => true,
                'transaction' => $existing,
                'wallet_balance' => (string) $user->fresh()->wallet_balance,
            ];
        }

        $resolved = $this->verifyNigerianBankAccount($bankCode, $accountNumber);
        $resolvedName = (string) ($resolved['account_name'] ?? '');

        if (! $this->namesMatch($resolvedName, $accountName)) {
            throw new RuntimeException('Account name verification failed. Please verify account details and try again.');
        }

        /** @var WalletPaymentTransaction|null $paymentTransaction */
        $paymentTransaction = null;
        /** @var WalletTransaction|null $walletTransaction */
        $walletTransaction = null;

        DB::transaction(function () use ($user, $amount, $idempotencyKey, $bankCode, $accountNumber, $resolvedName, &$paymentTransaction, &$walletTransaction): void {
            $lockedUser = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $balance = (float) $lockedUser->wallet_balance;

            if ($balance < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $paymentTransaction = WalletPaymentTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'direction' => WalletPaymentTransaction::DIRECTION_WITHDRAWAL,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => (string) config('wallet.default_currency', 'NGN'),
                'status' => WalletPaymentTransaction::STATUS_INITIALIZED,
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
                'account_name' => $resolvedName,
            ]);

            $before = $balance;
            $after = $before - $amount;

            $lockedUser->update([
                'wallet_balance' => $after,
            ]);

            $walletTransaction = WalletTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'wallet_payment_transaction_id' => $paymentTransaction->id,
                'type' => WalletTransaction::TYPE_WITHDRAWAL,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => WalletTransaction::STATUS_PENDING,
                'description' => 'Wallet withdrawal initiated.',
            ]);
        });

        if (! $paymentTransaction instanceof WalletPaymentTransaction) {
            throw new RuntimeException('Failed to initialize wallet withdrawal transaction.');
        }

        if (! $walletTransaction instanceof WalletTransaction) {
            throw new RuntimeException('Failed to initialize wallet withdrawal transaction.');
        }

        $paymentTx = $paymentTransaction;
        $walletTx = $walletTransaction;
        $reference = $this->generateReference('WWD');

        try {
            $recipient = $this->paystackService->createTransferRecipient(
                name: (string) $paymentTx->account_name,
                accountNumber: (string) $paymentTx->account_number,
                bankCode: (string) $paymentTx->bank_code,
            );

            $recipientCode = (string) ($recipient['data']['recipient_code'] ?? '');

            if ($recipientCode === '') {
                throw new RuntimeException('Paystack did not return a valid transfer recipient code.');
            }

            $transfer = $this->paystackService->initiateTransfer(
                recipientCode: $recipientCode,
                reference: $reference,
                amount: $amount,
                reason: $reason,
            );

            $paymentTx->update([
                'recipient_code' => $recipientCode,
                'reference' => $reference,
                'status' => WalletPaymentTransaction::STATUS_PENDING,
                'gateway_response' => $transfer,
            ]);

            $walletTx->update([
                'reference' => $reference,
                'status' => WalletTransaction::STATUS_PENDING,
                'metadata' => [
                    'recipient_code' => $recipientCode,
                ],
            ]);

            $freshUser = $user->fresh();

            if ($freshUser) {
                $this->notificationService->sendAccountActivity(
                    user: $freshUser,
                    title: 'Withdrawal initiated',
                    message: 'Your wallet withdrawal request of NGN '.number_format($amount, 2, '.', '').' has been initiated.',
                    data: [
                        'reference' => $reference,
                        'status' => WalletPaymentTransaction::STATUS_PENDING,
                        'amount' => number_format($amount, 2, '.', ''),
                    ],
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
                );
            }

            return [
                'reused' => false,
                'transaction' => $paymentTx->fresh(),
                'wallet_balance' => (string) $user->fresh()->wallet_balance,
            ];
        } catch (RuntimeException $exception) {
            $this->applyFailedWithdrawal($paymentTx, [
                'message' => $exception->getMessage(),
            ], 'initiate_failed');

            throw $exception;
        }
    }

    public function applySuccessfulDeposit(WalletPaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $notificationUserId = null;
        $notificationData = null;

        DB::transaction(function () use ($transaction, $payload, $event, &$notificationUserId, &$notificationData): void {
            $tx = WalletPaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status === WalletPaymentTransaction::STATUS_SUCCESS) {
                return;
            }

            $user = User::query()->where('id', $tx->user_id)->lockForUpdate()->firstOrFail();

            $before = (float) $user->wallet_balance;
            $amount = (float) $tx->amount;
            $after = $before + $amount;

            $user->update([
                'wallet_balance' => $after,
            ]);

            $tx->update([
                'status' => WalletPaymentTransaction::STATUS_SUCCESS,
                'last_webhook_event' => $event,
                'processed_at' => now(),
                'gateway_response' => $payload,
            ]);

            WalletTransaction::query()->firstOrCreate(
                [
                    'wallet_payment_transaction_id' => $tx->id,
                    'type' => WalletTransaction::TYPE_DEPOSIT,
                    'status' => WalletTransaction::STATUS_SUCCESS,
                ],
                [
                    'user_id' => $tx->user_id,
                    'direction' => WalletTransaction::DIRECTION_CREDIT,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference' => $tx->reference,
                    'description' => 'Wallet deposit successful.',
                ]
            );

            $notificationUserId = $tx->user_id;
            $notificationData = [
                'reference' => $tx->reference,
                'status' => WalletPaymentTransaction::STATUS_SUCCESS,
                'amount' => number_format($amount, 2, '.', ''),
                'balance_after' => number_format($after, 2, '.', ''),
            ];
        });

        if (is_string($notificationUserId) && is_array($notificationData)) {
            /** @var User|null $user */
            $user = User::query()->whereKey($notificationUserId)->first();

            if ($user) {
                $this->notificationService->sendAccountActivity(
                    user: $user,
                    title: 'Deposit successful',
                    message: 'Your wallet was credited with NGN '.$notificationData['amount'].'.',
                    data: $notificationData,
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH, NotificationService::CHANNEL_EMAIL],
                );
            }
        }
    }

    public function applyFailedDeposit(WalletPaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $transaction->refresh();

        if (in_array($transaction->status, [WalletPaymentTransaction::STATUS_SUCCESS, WalletPaymentTransaction::STATUS_FAILED], true)) {
            return;
        }

        $transaction->update([
            'status' => WalletPaymentTransaction::STATUS_FAILED,
            'last_webhook_event' => $event,
            'gateway_response' => $payload,
        ]);

        $user = $transaction->user()->first();

        if ($user) {
            $this->notificationService->sendAccountActivity(
                user: $user,
                title: 'Deposit failed',
                message: 'Your wallet deposit could not be completed. Please try again.',
                data: [
                    'reference' => $transaction->reference,
                    'status' => WalletPaymentTransaction::STATUS_FAILED,
                    'amount' => (string) $transaction->amount,
                ],
                channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
            );
        }
    }

    public function applySuccessfulWithdrawal(WalletPaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $notificationUserId = null;
        $notificationData = null;

        DB::transaction(function () use ($transaction, $payload, $event, &$notificationUserId, &$notificationData): void {
            $tx = WalletPaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status === WalletPaymentTransaction::STATUS_SUCCESS) {
                return;
            }

            $tx->update([
                'status' => WalletPaymentTransaction::STATUS_SUCCESS,
                'last_webhook_event' => $event,
                'processed_at' => now(),
                'gateway_response' => $payload,
            ]);

            $walletTx = WalletTransaction::query()
                ->where('wallet_payment_transaction_id', $tx->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL)
                ->latest()
                ->first();

            if ($walletTx && $walletTx->status !== WalletTransaction::STATUS_SUCCESS) {
                $walletTx->update([
                    'status' => WalletTransaction::STATUS_SUCCESS,
                    'reference' => $tx->reference,
                    'description' => 'Wallet withdrawal successful.',
                ]);
            }

            $notificationUserId = $tx->user_id;
            $notificationData = [
                'reference' => $tx->reference,
                'status' => WalletPaymentTransaction::STATUS_SUCCESS,
                'amount' => (string) $tx->amount,
            ];
        });

        if (is_string($notificationUserId) && is_array($notificationData)) {
            /** @var User|null $user */
            $user = User::query()->whereKey($notificationUserId)->first();

            if ($user) {
                $this->notificationService->sendAccountActivity(
                    user: $user,
                    title: 'Withdrawal successful',
                    message: 'Your wallet withdrawal of NGN '.$notificationData['amount'].' was successful.',
                    data: $notificationData,
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH, NotificationService::CHANNEL_EMAIL],
                );
            }
        }
    }

    public function applyFailedWithdrawal(WalletPaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $notificationUserId = null;
        $notificationData = null;

        DB::transaction(function () use ($transaction, $payload, $event, &$notificationUserId, &$notificationData): void {
            $tx = WalletPaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status === WalletPaymentTransaction::STATUS_SUCCESS) {
                return;
            }

            if ($tx->status === WalletPaymentTransaction::STATUS_FAILED) {
                return;
            }

            $user = User::query()->where('id', $tx->user_id)->lockForUpdate()->firstOrFail();

            $withdrawalTx = WalletTransaction::query()
                ->where('wallet_payment_transaction_id', $tx->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL)
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $withdrawalTx) {
                $tx->update([
                    'status' => WalletPaymentTransaction::STATUS_FAILED,
                    'last_webhook_event' => $event,
                    'gateway_response' => $payload,
                ]);

                return;
            }

            if ($withdrawalTx->status !== WalletTransaction::STATUS_REVERSED) {
                $before = (float) $user->wallet_balance;
                $amount = (float) $tx->amount;
                $after = $before + $amount;

                $user->update([
                    'wallet_balance' => $after,
                ]);

                $withdrawalTx->update([
                    'status' => WalletTransaction::STATUS_REVERSED,
                    'description' => 'Wallet withdrawal reversed after failure.',
                ]);

                WalletTransaction::query()->create([
                    'user_id' => $user->id,
                    'wallet_payment_transaction_id' => $tx->id,
                    'type' => WalletTransaction::TYPE_WITHDRAWAL_REVERSAL,
                    'direction' => WalletTransaction::DIRECTION_CREDIT,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'status' => WalletTransaction::STATUS_SUCCESS,
                    'reference' => $tx->reference,
                    'description' => 'Wallet withdrawal reversal credit.',
                    'metadata' => [
                        'reason' => 'withdrawal_failed',
                    ],
                ]);
            }

            $tx->update([
                'status' => WalletPaymentTransaction::STATUS_FAILED,
                'last_webhook_event' => $event,
                'processed_at' => now(),
                'gateway_response' => $payload,
            ]);

            $notificationUserId = $tx->user_id;
            $notificationData = [
                'reference' => $tx->reference,
                'status' => WalletPaymentTransaction::STATUS_FAILED,
                'amount' => (string) $tx->amount,
                'reversed' => true,
            ];
        });

        if (is_string($notificationUserId) && is_array($notificationData)) {
            /** @var User|null $user */
            $user = User::query()->whereKey($notificationUserId)->first();

            if ($user) {
                $this->notificationService->sendAccountActivity(
                    user: $user,
                    title: 'Withdrawal failed',
                    message: 'Your wallet withdrawal failed and any debited amount has been reversed.',
                    data: $notificationData,
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
                );
            }
        }
    }

    public function debitForOrder(User $user, Order $order, float $amount): void
    {
        DB::transaction(function () use ($user, $order, $amount): void {
            $lockedUser = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $balance = (float) $lockedUser->wallet_balance;

            if ($balance < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $before = $balance;
            $after = $before - $amount;

            $lockedUser->update([
                'wallet_balance' => $after,
            ]);

            WalletTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'type' => WalletTransaction::TYPE_ORDER_PAYMENT,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => WalletTransaction::STATUS_SUCCESS,
                'reference' => $order->order_number,
                'description' => 'Wallet payment for order '.$order->order_number,
                'metadata' => [
                    'order_id' => $order->id,
                ],
            ]);
        });
    }

    private function assertMinimumDeposit(float $amount): void
    {
        $minimum = (float) config('wallet.min_deposit', 100);

        if ($amount < $minimum) {
            throw new RuntimeException('Minimum deposit amount is '.number_format($minimum, 2, '.', '').' NGN.');
        }
    }

    private function assertMinimumWithdrawal(float $amount): void
    {
        $minimum = (float) config('wallet.min_withdrawal', 1000);

        if ($amount < $minimum) {
            throw new RuntimeException('Minimum withdrawal amount is '.number_format($minimum, 2, '.', '').' NGN.');
        }
    }

    private function namesMatch(string $resolvedName, string $providedName): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/', '', strtolower(trim($value))) ?? '';

        $a = $normalize($resolvedName);
        $b = $normalize($providedName);

        if ($a === '' || $b === '') {
            return false;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    private function generateReference(string $prefix): string
    {
        return $prefix.'-'.strtoupper(now()->format('YmdHis')).'-'.Str::upper(Str::random(8));
    }
}
