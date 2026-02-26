<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApnPushService
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function send(string $deviceToken, string $title, string $message, array $data = []): array
    {
        $jwt = $this->createJwt();
        $bundleId = (string) config('notification_channels.apn.bundle_id');

        if ($bundleId === '') {
            throw new RuntimeException('APN bundle id is not configured.');
        }

        $environment = (string) config('notification_channels.apn.environment', 'development');
        $baseUrl = $environment === 'production'
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'sound' => 'default',
            ],
            'data' => $data,
        ];

        $response = Http::withHeaders([
            'authorization' => 'bearer '.$jwt,
            'apns-topic' => $bundleId,
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('notification_channels.apn.timeout_seconds', 15))
            ->post($baseUrl.'/3/device/'.$deviceToken, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('APNs push request failed with HTTP '.$response->status().'.');
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    private function createJwt(): string
    {
        $keyId = (string) config('notification_channels.apn.key_id');
        $teamId = (string) config('notification_channels.apn.team_id');
        $privateKey = $this->normalizePrivateKey((string) config('notification_channels.apn.private_key'));

        if ($keyId === '' || $teamId === '' || $privateKey === '') {
            throw new RuntimeException('APN credentials are not fully configured.');
        }

        $header = [
            'alg' => 'ES256',
            'kid' => $keyId,
        ];

        $claims = [
            'iss' => $teamId,
            'iat' => time(),
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $claimsEncoded = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $unsigned = $headerEncoded.'.'.$claimsEncoded;

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new RuntimeException('Failed to sign APN JWT.');
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    private function normalizePrivateKey(string $key): string
    {
        $normalized = trim($key);

        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, '\\n')) {
            $normalized = str_replace('\\n', "\n", $normalized);
        }

        return $normalized;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
