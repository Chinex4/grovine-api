<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function initializeTransaction(string $email, string $reference, float $amount, array $metadata = []): array
    {
        $amountKobo = (int) round($amount * 100);

        try {
            $response = $this->request()
                ->asJson()
                ->post('/transaction/initialize', [
                    'email' => $email,
                    'amount' => $amountKobo,
                    'currency' => (string) config('paystack.currency', 'NGN'),
                    'reference' => $reference,
                    'callback_url' => config('paystack.callback_url'),
                    'metadata' => $metadata,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack initialize failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
                'reference' => $reference,
            ]);

            throw new RuntimeException('Failed to initialize payment.');
        }

        $this->assertSuccessfulResponse($response, 'initialize');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $response = $this->request()
                ->get('/transaction/verify/'.urlencode($reference))
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack verify failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
                'reference' => $reference,
            ]);

            throw new RuntimeException('Failed to verify payment.');
        }

        $this->assertSuccessfulResponse($response, 'verify');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function listBanksNigeria(): array
    {
        try {
            $response = $this->request()
                ->get('/bank', [
                    'country' => 'nigeria',
                    'currency' => 'NGN',
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack list banks failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
            ]);

            throw new RuntimeException('Failed to fetch bank list.');
        }

        $this->assertSuccessfulResponse($response, 'list_banks');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveBankAccount(string $accountNumber, string $bankCode): array
    {
        try {
            $response = $this->request()
                ->get('/bank/resolve', [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack resolve account failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            throw new RuntimeException('Failed to verify bank account details.');
        }

        $this->assertSuccessfulResponse($response, 'resolve_account');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): array
    {
        try {
            $response = $this->request()
                ->asJson()
                ->post('/transferrecipient', [
                    'type' => 'nuban',
                    'name' => $name,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'currency' => 'NGN',
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack transfer recipient failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            throw new RuntimeException('Failed to create transfer recipient.');
        }

        $this->assertSuccessfulResponse($response, 'create_transfer_recipient');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateTransfer(string $recipientCode, string $reference, float $amount, ?string $reason = null): array
    {
        $amountKobo = (int) round($amount * 100);

        try {
            $response = $this->request()
                ->asJson()
                ->post('/transfer', [
                    'source' => 'balance',
                    'amount' => $amountKobo,
                    'recipient' => $recipientCode,
                    'reference' => $reference,
                    'reason' => $reason,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            Log::error('Paystack transfer failed', [
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
                'reference' => $reference,
            ]);

            throw new RuntimeException('Failed to initiate bank transfer.');
        }

        $this->assertSuccessfulResponse($response, 'initiate_transfer');

        return $response;
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        $secret = (string) config('paystack.webhook_secret');

        if ($secret === '' || ! $signature) {
            return false;
        }

        $computed = hash_hmac('sha512', $payload, $secret);

        return hash_equals($computed, $signature);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('paystack.base_url'), '/'))
            ->withToken($this->secretKey())
            ->acceptJson()
            ->timeout((int) config('paystack.timeout_seconds', 15))
            ->retry(2, 250);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertSuccessfulResponse(array $response, string $context): void
    {
        if (! ($response['status'] ?? false)) {
            Log::warning('Paystack unsuccessful response', [
                'context' => $context,
                'response' => $response,
            ]);

            throw new RuntimeException('Paystack returned an unsuccessful response.');
        }
    }

    private function secretKey(): string
    {
        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        return $secret;
    }
}
