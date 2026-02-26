<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_deposit_with_idempotency_and_webhook_updates_balance(): void
    {
        config()->set('paystack.secret_key', 'paystack_test_secret');
        config()->set('paystack.webhook_secret', 'paystack_test_secret');
        config()->set('paystack.callback_url', 'https://app.grovine.ng/paystack/callback');

        $user = User::factory()->create([
            'wallet_balance' => 0,
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/wallet123',
                    'access_code' => 'wallet123',
                ],
            ], 200),
        ]);

        $first = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-deposit-001',
        ])->postJson('/api/wallet/deposits/initialize', [
            'amount' => 1000,
        ])->assertCreated();

        $reference = (string) $first->json('data.transaction.reference');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-deposit-001',
        ])->postJson('/api/wallet/deposits/initialize', [
            'amount' => 1000,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Existing wallet deposit session returned successfully.')
            ->assertJsonPath('data.transaction.reference', $reference);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'status' => 'success',
                'amount' => 100000,
            ],
        ];

        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
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
        )->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/balance')
            ->assertOk()
            ->assertJsonPath('data.balance', '1000.00');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'DEPOSIT')
            ->assertJsonPath('data.0.amount', '1000.00');
    }

    public function test_user_can_fetch_banks_verify_account_and_withdraw_with_constraints(): void
    {
        config()->set('paystack.secret_key', 'paystack_test_secret');
        config()->set('paystack.webhook_secret', 'paystack_test_secret');

        $user = User::factory()->create([
            'wallet_balance' => 5000,
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->issueForUser($user)['token'];

        Http::fake([
            'https://api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'message' => 'Account resolved',
                'data' => [
                    'account_number' => '0123456789',
                    'account_name' => 'John Doe',
                    'bank_id' => 1,
                ],
            ], 200),
            'https://api.paystack.co/bank*' => Http::response([
                'status' => true,
                'message' => 'Banks fetched',
                'data' => [
                    ['name' => 'Access Bank', 'code' => '044'],
                    ['name' => 'GTBank', 'code' => '058'],
                ],
            ], 200),
            'https://api.paystack.co/transferrecipient' => Http::response([
                'status' => true,
                'message' => 'Recipient created',
                'data' => [
                    'recipient_code' => 'RCP_test_123',
                ],
            ], 200),
            'https://api.paystack.co/transfer' => Http::response([
                'status' => true,
                'message' => 'Transfer queued',
                'data' => [
                    'status' => 'success',
                ],
            ], 200),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/banks/nigeria')
            ->assertOk()
            ->assertJsonPath('data.0.code', '044');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/wallet/verify-account', [
                'bank_code' => '044',
                'account_number' => '0123456789',
            ])
            ->assertOk()
            ->assertJsonPath('data.account_name', 'John Doe');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-withdraw-min',
        ])->postJson('/api/wallet/withdrawals', [
            'amount' => 900,
            'bank_code' => '044',
            'account_number' => '0123456789',
            'account_name' => 'John Doe',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Minimum withdrawal amount is 1000.00 NGN.');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-withdraw-bad-name',
        ])->postJson('/api/wallet/withdrawals', [
            'amount' => 1200,
            'bank_code' => '044',
            'account_number' => '0123456789',
            'account_name' => 'Jane Wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Account name verification failed. Please verify account details and try again.');

        $withdraw = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-withdraw-001',
        ])->postJson('/api/wallet/withdrawals', [
            'amount' => 1200,
            'bank_code' => '044',
            'account_number' => '0123456789',
            'account_name' => 'John Doe',
            'reason' => 'Test withdrawal',
        ])->assertCreated();

        $reference = (string) $withdraw->json('data.transaction.reference');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'wallet-withdraw-001',
        ])->postJson('/api/wallet/withdrawals', [
            'amount' => 1200,
            'bank_code' => '044',
            'account_number' => '0123456789',
            'account_name' => 'John Doe',
        ])
            ->assertOk()
            ->assertJsonPath('data.transaction.reference', $reference);

        $payload = [
            'event' => 'transfer.success',
            'data' => [
                'reference' => $reference,
                'status' => 'success',
            ],
        ];

        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
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
        )->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/balance')
            ->assertOk()
            ->assertJsonPath('data.balance', '3800.00');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/wallet/transactions?type=WITHDRAWAL')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'WITHDRAWAL');
    }
}
