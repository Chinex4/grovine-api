<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\WalletPaymentTransaction;
use App\Services\CheckoutService;
use App\Services\PaystackService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaystackService $paystackService,
        private readonly CheckoutService $checkoutService,
        private readonly WalletService $walletService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Paystack-Signature');

        if (! $this->paystackService->verifyWebhookSignature($payload, $signature)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
            ], 422);
        }

        $event = (string) ($decoded['event'] ?? '');
        $reference = (string) ($decoded['data']['reference'] ?? '');

        if ($reference === '') {
            return response()->json([
                'message' => 'Webhook processed.',
            ]);
        }

        $status = strtolower((string) ($decoded['data']['status'] ?? ''));

        $orderPayment = PaymentTransaction::query()->where('reference', $reference)->first();

        if ($orderPayment) {
            if ($event === 'charge.success' && $status === 'success') {
                $this->checkoutService->applySuccessfulPayment($orderPayment, $decoded, $event);
            } elseif (in_array($event, ['charge.failed', 'charge.abandoned'], true)) {
                $this->checkoutService->applyFailedPayment($orderPayment, $decoded, $event);
            }

            return response()->json([
                'message' => 'Webhook processed successfully.',
            ]);
        }

        $walletPayment = WalletPaymentTransaction::query()->where('reference', $reference)->first();

        if (! $walletPayment) {
            return response()->json([
                'message' => 'Webhook acknowledged (reference not tracked).',
            ]);
        }

        if ($walletPayment->direction === WalletPaymentTransaction::DIRECTION_DEPOSIT) {
            if ($event === 'charge.success' && $status === 'success') {
                $this->walletService->applySuccessfulDeposit($walletPayment, $decoded, $event);
            } elseif (in_array($event, ['charge.failed', 'charge.abandoned'], true)) {
                $this->walletService->applyFailedDeposit($walletPayment, $decoded, $event);
            }
        }

        if ($walletPayment->direction === WalletPaymentTransaction::DIRECTION_WITHDRAWAL) {
            if (in_array($event, ['transfer.success', 'transfer.processed'], true) || $status === 'success') {
                $this->walletService->applySuccessfulWithdrawal($walletPayment, $decoded, $event);
            } elseif (in_array($event, ['transfer.failed', 'transfer.reversed', 'transfer.rejected'], true) || in_array($status, ['failed', 'reversed'], true)) {
                $this->walletService->applyFailedWithdrawal($walletPayment, $decoded, $event);
            }
        }

        return response()->json([
            'message' => 'Webhook processed successfully.',
        ]);
    }
}
