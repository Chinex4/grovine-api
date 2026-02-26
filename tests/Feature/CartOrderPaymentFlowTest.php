<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CartOrderPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_cart_checkout_with_idempotency_and_paystack_webhook(): void
    {
        config()->set('paystack.secret_key', 'paystack_test_secret');
        config()->set('paystack.webhook_secret', 'paystack_test_secret');
        config()->set('paystack.callback_url', 'https://app.grovine.ng/paystack/callback');

        $user = User::factory()->create([
            'email' => 'buyer@example.com',
            'email_verified_at' => now(),
            'role' => User::ROLE_USER,
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Grape Pack',
            'slug' => 'grape-pack',
            'description' => 'Fresh grape basket.',
            'image_url' => 'products/grape-pack.jpg',
            'price' => 5500,
            'stock' => 20,
            'discount' => 500,
            'is_active' => true,
            'is_recommended' => true,
            'is_rush_hour_offer' => true,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.item_count', 2);

        $cart = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/cart')
            ->assertOk();

        $cartItemId = (string) $cart->json('data.items.0.id');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/cart/items/'.$cartItemId, [
                'quantity' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.item_count', 3);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                ],
            ], 200),
        ]);

        $checkout = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'idem-checkout-001',
        ])->postJson('/api/checkout', [
            'payment_method' => 'paystack',
            'delivery_address' => '5 Banana Street, Lagos',
            'rider_note' => 'Ring the bell once',
            'delivery_type' => 'immediate',
        ])->assertCreated();

        $orderId = (string) $checkout->json('data.order.id');
        $reference = (string) $checkout->json('data.payment.reference');

        $this->assertNotEmpty($reference);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'idem-checkout-001',
        ])->postJson('/api/checkout', [
            'payment_method' => 'paystack',
            'delivery_address' => '5 Banana Street, Lagos',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Existing checkout session returned successfully.')
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonPath('data.payment.reference', $reference);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);

        $webhookPayload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'status' => 'success',
                'amount' => 1500000,
            ],
        ];

        $raw = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $raw, 'paystack_test_secret');

        $this->call(
            'POST',
            '/api/payments/paystack/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $raw,
        )
            ->assertOk()
            ->assertJsonPath('message', 'Webhook processed successfully.');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_IN_TRANSIT,
            'payment_status' => Order::PAYMENT_PAID,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/orders/'.$orderId.'/status', [
                'status' => Order::STATUS_DELIVERED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_DELIVERED);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/orders?bucket=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Order::STATUS_DELIVERED);
    }

    public function test_chef_and_admin_can_update_order_status_but_regular_user_cannot_update_others(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_USER, 'email_verified_at' => now()]);
        $otherUser = User::factory()->create(['role' => User::ROLE_USER, 'email_verified_at' => now()]);
        $chef = User::factory()->create(['role' => User::ROLE_CHEF, 'email_verified_at' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);

        $order = Order::query()->create([
            'order_number' => 'ORD-TEST-10001',
            'user_id' => $owner->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_method' => Order::PAYMENT_METHOD_PAYSTACK,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 1000,
            'delivery_fee' => 0,
            'service_fee' => 0,
            'affiliate_fee' => 0,
            'total' => 1000,
        ]);

        $otherToken = app(JwtService::class)->issueForUser($otherUser)['token'];
        $chefToken = app(JwtService::class)->issueForUser($chef)['token'];
        $adminToken = app(JwtService::class)->issueForUser($admin)['token'];

        $this->withHeaders(['Authorization' => 'Bearer '.$otherToken])
            ->patchJson('/api/orders/'.$order->id.'/status', [
                'status' => Order::STATUS_IN_TRANSIT,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');

        $this->withHeaders(['Authorization' => 'Bearer '.$chefToken])
            ->patchJson('/api/orders/'.$order->id.'/status', [
                'status' => Order::STATUS_IN_TRANSIT,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_IN_TRANSIT);

        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->patchJson('/api/orders/'.$order->id.'/status', [
                'status' => Order::STATUS_DELIVERED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_DELIVERED);
    }

    public function test_user_can_checkout_with_wallet_when_balance_is_sufficient(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 10000,
            'email_verified_at' => now(),
            'role' => User::ROLE_USER,
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        $category = Category::query()->create([
            'name' => 'Fruits',
            'slug' => 'fruits',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Apple Pack',
            'slug' => 'apple-pack',
            'description' => 'Apple basket.',
            'image_url' => 'products/apple-pack.jpg',
            'price' => 3000,
            'stock' => 10,
            'discount' => 200,
            'is_active' => true,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertCreated();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-checkout-001',
        ])->postJson('/api/checkout', [
            'payment_method' => 'wallet',
            'delivery_address' => 'Lagos, Nigeria',
        ])->assertCreated();

        $orderId = (string) $response->json('data.order.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_IN_TRANSIT,
            'payment_status' => Order::PAYMENT_PAID,
            'payment_method' => Order::PAYMENT_METHOD_WALLET,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/balance')
            ->assertOk()
            ->assertJsonPath('data.balance', '4400.00');
    }
}
