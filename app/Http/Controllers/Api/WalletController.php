<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    public function balance(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Wallet balance fetched successfully.',
            'data' => [
                'balance' => (string) $request->user()->wallet_balance,
                'currency' => (string) config('wallet.default_currency', 'NGN'),
            ],
        ]);
    }

    public function initializeDeposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            return response()->json([
                'message' => 'A valid Idempotency-Key header is required for wallet deposits.',
            ], 422);
        }

        try {
            $result = $this->walletService->initializeDeposit(
                user: $request->user(),
                amount: (float) $validated['amount'],
                idempotencyKey: $idempotencyKey,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $statusCode = $result['reused'] ? 200 : 201;

        return response()->json([
            'message' => $result['reused'] ? 'Existing wallet deposit session returned successfully.' : 'Wallet deposit initialized successfully.',
            'data' => [
                'transaction' => $result['transaction'],
                'authorization_url' => $result['payment_data']['authorization_url'] ?? null,
                'access_code' => $result['payment_data']['access_code'] ?? null,
            ],
        ], $statusCode);
    }

    public function verifyDeposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $this->walletService->verifyDeposit($request->user(), $validated['reference']);
        } catch (RuntimeException $exception) {
            $status = $exception->getMessage() === 'Deposit reference not found.' ? 404 : 422;

            return response()->json([
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'message' => 'Wallet deposit verification processed successfully.',
            'data' => $result,
        ]);
    }

    public function banks(): JsonResponse
    {
        try {
            $result = $this->walletService->listNigerianBanks();
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => 'Banks fetched successfully.',
            'data' => $result['banks'],
        ]);
    }

    public function verifyAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'digits:10'],
        ]);

        try {
            $result = $this->walletService->verifyNigerianBankAccount(
                bankCode: $validated['bank_code'],
                accountNumber: $validated['account_number'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Account verified successfully.',
            'data' => $result,
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'digits:10'],
            'account_name' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            return response()->json([
                'message' => 'A valid Idempotency-Key header is required for withdrawals.',
            ], 422);
        }

        try {
            $result = $this->walletService->withdraw(
                user: $request->user(),
                amount: (float) $validated['amount'],
                idempotencyKey: $idempotencyKey,
                bankCode: $validated['bank_code'],
                accountNumber: $validated['account_number'],
                accountName: $validated['account_name'],
                reason: $validated['reason'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $result['reused'] ? 'Existing withdrawal session returned successfully.' : 'Withdrawal initiated successfully.',
            'data' => [
                'transaction' => $result['transaction'],
                'wallet_balance' => $result['wallet_balance'],
            ],
        ], $result['reused'] ? 200 : 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:DEPOSIT,WITHDRAWAL,WITHDRAWAL_REVERSAL,ORDER_PAYMENT,REFERRAL_BONUS'],
            'status' => ['nullable', 'in:PENDING,SUCCESS,FAILED,REVERSED'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $query = WalletTransaction::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $transactions = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Wallet transaction history fetched successfully.',
            'data' => $transactions,
        ]);
    }
}
