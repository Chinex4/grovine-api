<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\CheckoutService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaystackService $paystackService,
    ) {
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'in:wallet,paystack'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'rider_note' => ['nullable', 'string', 'max:1000'],
            'delivery_type' => ['sometimes', 'in:immediate,scheduled'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            return response()->json([
                'message' => 'A valid Idempotency-Key header is required for checkout.',
            ], 422);
        }

        try {
            if (($validated['payment_method'] ?? null) === Order::PAYMENT_METHOD_WALLET) {
                $result = $this->checkoutService->checkoutWithWallet(
                    user: $request->user(),
                    idempotencyKey: $idempotencyKey,
                    payload: $validated,
                );
            } else {
                $result = $this->checkoutService->checkoutWithPaystack(
                    user: $request->user(),
                    idempotencyKey: $idempotencyKey,
                    payload: $validated,
                );
            }
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            $statusCode = match ($message) {
                'Cart is empty.', 'Insufficient wallet balance.' => 422,
                default => 502,
            };

            return response()->json([
                'message' => $message,
            ], $statusCode);
        }

        $statusCode = $result['reused'] ? 200 : 201;

        /** @var Order $order */
        $order = $result['order'];
        /** @var PaymentTransaction $transaction */
        $transaction = $result['payment_transaction'];

        return response()->json([
            'message' => $result['reused'] ? 'Existing checkout session returned successfully.' : 'Checkout initiated successfully.',
            'data' => [
                'order' => $order->loadMissing(['items', 'payments']),
                'payment' => [
                    'method' => $transaction->payment_method,
                    'status' => $transaction->status,
                    'reference' => $transaction->reference,
                    'authorization_url' => $result['payment_data']['authorization_url'] ?? null,
                    'access_code' => $result['payment_data']['access_code'] ?? null,
                ],
                'wallet_balance' => (string) $request->user()->fresh()->wallet_balance,
            ],
        ], $statusCode);
    }

    public function verifyPaystackPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
        ]);

        $transaction = PaymentTransaction::query()
            ->with(['order'])
            ->where('reference', $validated['reference'])
            ->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Payment reference not found.',
            ], 404);
        }

        $user = $request->user();

        if ($transaction->user_id !== $user->id && ! $user->hasRole('admin', 'chef')) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        try {
            $response = $this->paystackService->verifyTransaction($validated['reference']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $status = strtolower((string) ($response['data']['status'] ?? ''));

        if ($status === 'success') {
            $this->checkoutService->applySuccessfulPayment($transaction, $response, 'manual_verify');
        } else {
            $this->checkoutService->applyFailedPayment($transaction, $response, 'manual_verify');
        }

        return response()->json([
            'message' => 'Payment verification processed successfully.',
            'data' => [
                'reference' => $transaction->reference,
                'status' => $transaction->fresh()->status,
                'order_status' => $transaction->order()->first()?->status,
                'payment_status' => $transaction->order()->first()?->payment_status,
            ],
        ]);
    }
}
