<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly PaystackService $paystackService,
        private readonly NotificationService $notificationService,
        private readonly ReferralService $referralService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function checkoutWithPaystack(User $user, string $idempotencyKey, array $payload): array
    {
        $existing = PaymentTransaction::query()
            ->with(['order.items', 'order.user'])
            ->where('user_id', $user->id)
            ->where('payment_method', Order::PAYMENT_METHOD_PAYSTACK)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'reused' => true,
                'order' => $existing->order,
                'payment_transaction' => $existing,
                'payment_data' => $existing->gateway_response['data'] ?? null,
            ];
        }

        $cart = $this->cartService->summary($user);

        if ($cart['items']->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $pricing = $this->calculateTotals((float) $cart['total']);

        $order = null;
        $transaction = null;

        DB::transaction(function () use ($user, $payload, $idempotencyKey, $cart, $pricing, &$order, &$transaction): void {
            $order = $this->createOrderFromCart(
                user: $user,
                cart: $cart,
                payload: $payload,
                paymentMethod: Order::PAYMENT_METHOD_PAYSTACK,
                status: Order::STATUS_AWAITING_PAYMENT,
                paymentStatus: Order::PAYMENT_PENDING,
                paidAt: null,
                pricing: $pricing,
            );

            $transaction = PaymentTransaction::query()->create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'gateway' => 'paystack',
                'payment_method' => Order::PAYMENT_METHOD_PAYSTACK,
                'idempotency_key' => $idempotencyKey,
                'amount' => $pricing['total'],
                'currency' => (string) config('paystack.currency', 'NGN'),
                'status' => PaymentTransaction::STATUS_INITIALIZED,
            ]);
        });

        $reference = $this->generatePaymentReference($order->order_number);

        try {
            $paystackResponse = $this->paystackService->initializeTransaction(
                email: $user->email,
                reference: $reference,
                amount: (float) $transaction->getRawOriginal('amount'),
                metadata: [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'order_number' => $order->order_number,
                    'idempotency_key' => $idempotencyKey,
                    'type' => 'order_checkout',
                ],
            );

            $transaction->update([
                'reference' => $reference,
                'gateway_response' => $paystackResponse,
                'status' => PaymentTransaction::STATUS_INITIALIZED,
            ]);

            $user->cartItems()->delete();

            $this->notificationService->sendAccountActivity(
                user: $user,
                title: 'Order awaiting payment',
                message: 'Order '.$order->order_number.' has been created and is awaiting payment confirmation.',
                data: [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
                channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
            );

            return [
                'reused' => false,
                'order' => $order->fresh(['items', 'payments']),
                'payment_transaction' => $transaction->fresh(),
                'payment_data' => $paystackResponse['data'] ?? null,
            ];
        } catch (RuntimeException $exception) {
            $transaction->update([
                'reference' => $reference,
                'status' => PaymentTransaction::STATUS_FAILED,
                'gateway_response' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function checkoutWithWallet(User $user, string $idempotencyKey, array $payload): array
    {
        $existing = PaymentTransaction::query()
            ->with(['order.items', 'order.user'])
            ->where('user_id', $user->id)
            ->where('payment_method', Order::PAYMENT_METHOD_WALLET)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'reused' => true,
                'order' => $existing->order,
                'payment_transaction' => $existing,
            ];
        }

        $cart = $this->cartService->summary($user);

        if ($cart['items']->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $pricing = $this->calculateTotals((float) $cart['total']);

        $order = null;
        $transaction = null;

        DB::transaction(function () use ($user, $payload, $idempotencyKey, $cart, $pricing, &$order, &$transaction): void {
            $lockedUser = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $balance = (float) $lockedUser->wallet_balance;

            if ($balance < $pricing['total']) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $order = $this->createOrderFromCart(
                user: $lockedUser,
                cart: $cart,
                payload: $payload,
                paymentMethod: Order::PAYMENT_METHOD_WALLET,
                status: Order::STATUS_IN_TRANSIT,
                paymentStatus: Order::PAYMENT_PAID,
                paidAt: now(),
                pricing: $pricing,
            );

            $before = $balance;
            $after = $before - $pricing['total'];

            $lockedUser->update([
                'wallet_balance' => $after,
            ]);

            WalletTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'type' => WalletTransaction::TYPE_ORDER_PAYMENT,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => $pricing['total'],
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => WalletTransaction::STATUS_SUCCESS,
                'reference' => $order->order_number,
                'description' => 'Wallet payment for order '.$order->order_number,
                'metadata' => [
                    'order_id' => $order->id,
                ],
            ]);

            $transaction = PaymentTransaction::query()->create([
                'order_id' => $order->id,
                'user_id' => $lockedUser->id,
                'gateway' => 'wallet',
                'payment_method' => Order::PAYMENT_METHOD_WALLET,
                'idempotency_key' => $idempotencyKey,
                'reference' => $this->generatePaymentReference($order->order_number),
                'amount' => $pricing['total'],
                'currency' => (string) config('wallet.default_currency', 'NGN'),
                'status' => PaymentTransaction::STATUS_SUCCESS,
                'paid_at' => now(),
                'gateway_response' => [
                    'message' => 'Paid successfully with wallet.',
                ],
            ]);
        });

        $user->cartItems()->delete();

        $this->referralService->applyRewardsForPaidOrder($order);

        $this->notificationService->sendAccountActivity(
            user: $user,
            title: 'Order placed successfully',
            message: 'Your order '.$order->order_number.' was paid with wallet and is now in transit.',
            data: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
            ],
            channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
        );

        return [
            'reused' => false,
            'order' => $order->fresh(['items', 'payments']),
            'payment_transaction' => $transaction->fresh(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applySuccessfulPayment(PaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $statusChanged = false;
        $notificationTargetUserId = null;
        $notificationData = null;

        DB::transaction(function () use ($transaction, $payload, $event, &$statusChanged): void {
            $transaction->refresh();

            if ($transaction->status === PaymentTransaction::STATUS_SUCCESS) {
                return;
            }

            $transaction->update([
                'status' => PaymentTransaction::STATUS_SUCCESS,
                'last_webhook_event' => $event,
                'paid_at' => now(),
                'gateway_response' => $payload,
            ]);

            $order = $transaction->order()->lockForUpdate()->first();

            if (! $order) {
                return;
            }

            $updates = [
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ];

            if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                $updates['status'] = Order::STATUS_IN_TRANSIT;
            }

            $order->update($updates);
            $statusChanged = true;
        });

        if (! $statusChanged) {
            return;
        }

        $freshTransaction = $transaction->fresh(['order']);
        $order = $freshTransaction?->order;

        if ($freshTransaction && $freshTransaction->status === PaymentTransaction::STATUS_SUCCESS && $order) {
            $notificationTargetUserId = $order->user_id;
            $notificationData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'reference' => $freshTransaction->reference,
            ];
        }

        if ($order instanceof Order) {
            $this->referralService->applyRewardsForPaidOrder($order);
        }

        if (is_string($notificationTargetUserId) && is_array($notificationData)) {
            $user = User::query()->find($notificationTargetUserId);

            if ($user) {
                $this->notificationService->sendAccountActivity(
                    user: $user,
                    title: 'Payment confirmed',
                    message: 'Payment for order '.$notificationData['order_number'].' was confirmed successfully.',
                    data: $notificationData,
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH, NotificationService::CHANNEL_EMAIL],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyFailedPayment(PaymentTransaction $transaction, array $payload, ?string $event = null): void
    {
        $statusChanged = false;
        $shouldNotify = false;
        $notificationTargetUserId = null;
        $notificationData = null;

        DB::transaction(function () use ($transaction, $payload, $event, &$statusChanged): void {
            $transaction->refresh();

            if ($transaction->status === PaymentTransaction::STATUS_SUCCESS) {
                return;
            }

            if ($transaction->status === PaymentTransaction::STATUS_FAILED) {
                return;
            }

            $transaction->update([
                'status' => PaymentTransaction::STATUS_FAILED,
                'last_webhook_event' => $event,
                'gateway_response' => $payload,
            ]);

            $order = $transaction->order()->lockForUpdate()->first();

            if (! $order) {
                return;
            }

            if ($order->payment_status !== Order::PAYMENT_PAID) {
                $order->update([
                    'payment_status' => Order::PAYMENT_FAILED,
                ]);
            }

            $statusChanged = true;
        });

        if (! $statusChanged) {
            return;
        }

        $freshTransaction = $transaction->fresh(['order']);
        $order = $freshTransaction?->order;

        if ($freshTransaction && $freshTransaction->status === PaymentTransaction::STATUS_FAILED && $order) {
            $shouldNotify = true;
            $notificationTargetUserId = $order->user_id;
            $notificationData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'reference' => $freshTransaction->reference,
            ];
        }

        if ($shouldNotify && is_string($notificationTargetUserId) && is_array($notificationData)) {
            $user = User::query()->find($notificationTargetUserId);

            if ($user) {
                $this->notificationService->sendAccountActivity(
                    user: $user,
                    title: 'Payment failed',
                    message: 'Payment for order '.$notificationData['order_number'].' failed. Please try again.',
                    data: $notificationData,
                    channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
                );
            }
        }
    }

    public function addOrUpdateCartItem(User $user, Product $product, int $quantity): void
    {
        $user->cartItems()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'quantity' => $quantity,
                'unit_price' => $product->getRawOriginal('price'),
                'unit_discount' => $product->getRawOriginal('discount'),
            ]
        );
    }

    /**
     * @param array<string, mixed> $cart
     * @param array<string, mixed> $payload
     * @param array<string, float> $pricing
     */
    private function createOrderFromCart(
        User $user,
        array $cart,
        array $payload,
        string $paymentMethod,
        string $status,
        string $paymentStatus,
        $paidAt,
        array $pricing,
    ): Order {
        $order = Order::query()->create([
            'order_number' => $this->generateOrderNumber(),
            'user_id' => $user->id,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'subtotal' => $pricing['subtotal'],
            'delivery_fee' => $pricing['delivery_fee'],
            'service_fee' => $pricing['service_fee'],
            'affiliate_fee' => $pricing['affiliate_fee'],
            'total' => $pricing['total'],
            'delivery_address' => $payload['delivery_address'] ?? null,
            'rider_note' => $payload['rider_note'] ?? null,
            'delivery_type' => $payload['delivery_type'] ?? 'immediate',
            'scheduled_for' => $payload['scheduled_for'] ?? null,
            'paid_at' => $paidAt,
        ]);

        foreach ($cart['items'] as $item) {
            $unitPrice = (float) $item->getRawOriginal('unit_price');
            $unitDiscount = (float) $item->getRawOriginal('unit_discount');
            $qty = (int) $item->quantity;
            $lineTotal = max(($unitPrice - $unitDiscount) * $qty, 0);

            $order->items()->create([
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name ?? 'Unknown Product',
                'product_image_url' => $item->product?->getRawOriginal('image_url'),
                'unit_price' => $unitPrice,
                'unit_discount' => $unitDiscount,
                'quantity' => $qty,
                'line_total' => $lineTotal,
            ]);
        }

        return $order;
    }

    /**
     * @return array{subtotal:float,delivery_fee:float,service_fee:float,affiliate_fee:float,total:float}
     */
    private function calculateTotals(float $subtotal): array
    {
        $deliveryFee = (float) config('checkout.delivery_fee', 0);
        $serviceFee = (float) config('checkout.service_fee', 0);
        $affiliateFee = (float) config('checkout.affiliate_fee', 0);

        return [
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'service_fee' => $serviceFee,
            'affiliate_fee' => $affiliateFee,
            'total' => max($subtotal + $deliveryFee + $serviceFee - $affiliateFee, 0),
        ];
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-'.strtoupper(now()->format('Ymd')).'-'.Str::upper(Str::random(8));
    }

    private function generatePaymentReference(string $orderNumber): string
    {
        return 'GRV-'.$orderNumber.'-'.Str::upper(Str::random(6));
    }
}
