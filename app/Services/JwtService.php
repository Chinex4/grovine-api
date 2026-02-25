<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class JwtService
{
    /**
     * @return array{token:string,expires_at:string}
     */
    public function issueForUser(User $user): array
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addDays(config('jwt.ttl_days', 2));

        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => $user->id,
            'type' => 'access',
            'iat' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => (string) Str::uuid(),
        ];

        return [
            'token' => $this->encode($payload),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new RuntimeException('Invalid token payload.');
        }

        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new RuntimeException('Unsupported token algorithm.');
        }

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true)
        );

        if (! hash_equals($expected, $encodedSignature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        if (! isset($payload['exp']) || CarbonImmutable::now()->timestamp >= (int) $payload['exp']) {
            throw new RuntimeException('Token has expired.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true);

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padding = 4 - (strlen($data) % 4);

        if ($padding < 4) {
            $data .= str_repeat('=', $padding);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 token segment.');
        }

        return $decoded;
    }

    private function secret(): string
    {
        $secret = trim((string) config('jwt.secret', ''));

        if ($secret === '') {
            $secret = trim((string) config('app.key', ''));
        }

        if ($secret === '') {
            $secret = 'grovine-test-jwt-secret';
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('JWT secret is not valid base64.');
            }

            return $decoded;
        }

        return $secret;
    }
}
