<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebasePushService
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function send(string $token, string $title, string $message, array $data = []): array
    {
        $serverKey = (string) config('notification_channels.firebase.server_key');

        if ($serverKey === '') {
            throw new RuntimeException('Firebase server key is not configured.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout((int) config('notification_channels.firebase.timeout_seconds', 15))
            ->post((string) config('notification_channels.firebase.endpoint'), [
                'to' => $token,
                'priority' => 'high',
                'notification' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'data' => $data,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Firebase push request failed with HTTP '.$response->status().'.');
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }
}
