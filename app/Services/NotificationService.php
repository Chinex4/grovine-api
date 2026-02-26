<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PUSH = 'push';

    public const CATEGORY_ACCOUNT_ACTIVITY = 'ACCOUNT_ACTIVITY';
    public const CATEGORY_SYSTEM = 'SYSTEM';
    public const CATEGORY_ADMIN = 'ADMIN';

    public function __construct(
        private readonly FirebasePushService $firebasePushService,
        private readonly ApnPushService $apnPushService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $channels
     * @return array<string, int>
     */
    public function sendAdminNotification(Collection $users, array $payload, array $channels, ?User $actor = null): array
    {
        $resolvedChannels = $this->resolveChannels($channels);

        $summary = [
            'in_app' => 0,
            'email' => 0,
            'push' => 0,
            'failed' => 0,
        ];

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $result = $this->sendToUser(
                user: $user,
                title: (string) $payload['title'],
                message: (string) $payload['message'],
                category: (string) ($payload['category'] ?? self::CATEGORY_SYSTEM),
                channels: $resolvedChannels,
                actionUrl: isset($payload['action_url']) ? (string) $payload['action_url'] : null,
                imageUrl: isset($payload['image_url']) ? (string) $payload['image_url'] : null,
                data: (array) ($payload['data'] ?? []),
                actor: $actor,
            );

            $summary['in_app'] += $result['in_app'];
            $summary['email'] += $result['email'];
            $summary['push'] += $result['push'];
            $summary['failed'] += $result['failed'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $channels
     */
    public function sendAccountActivity(User $user, string $title, string $message, array $data = [], array $channels = [self::CHANNEL_IN_APP]): void
    {
        $this->sendToUser(
            user: $user,
            title: $title,
            message: $message,
            category: self::CATEGORY_ACCOUNT_ACTIVITY,
            channels: $channels,
            actionUrl: null,
            imageUrl: null,
            data: $data,
            actor: null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $channels
     * @return array{in_app:int,email:int,push:int,failed:int}
     */
    public function sendToUser(
        User $user,
        string $title,
        string $message,
        string $category,
        array $channels,
        ?string $actionUrl = null,
        ?string $imageUrl = null,
        array $data = [],
        ?User $actor = null,
    ): array {
        $resolvedChannels = $this->resolveChannels($channels);

        $summary = [
            'in_app' => 0,
            'email' => 0,
            'push' => 0,
            'failed' => 0,
        ];

        if (in_array(self::CHANNEL_IN_APP, $resolvedChannels, true)) {
            $this->createInAppNotification(
                user: $user,
                category: $category,
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                imageUrl: $imageUrl,
                data: $data,
                actor: $actor,
            );

            $summary['in_app']++;
        }

        if (in_array(self::CHANNEL_EMAIL, $resolvedChannels, true)) {
            try {
                if ((bool) config('notification_channels.email.enabled', true)) {
                    Mail::to($user->email)->send(new GenericNotificationMail(
                        title: $title,
                        messageText: $message,
                        actionUrl: $actionUrl,
                        data: $data,
                    ));

                    $summary['email']++;
                    $this->logDispatch($user->id, self::CHANNEL_EMAIL, 'SENT', $title, $message, null, null, $actor?->id);
                } else {
                    $this->logDispatch($user->id, self::CHANNEL_EMAIL, 'SKIPPED', $title, $message, null, 'Email notifications are disabled.', $actor?->id);
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->logDispatch($user->id, self::CHANNEL_EMAIL, 'FAILED', $title, $message, null, $exception->getMessage(), $actor?->id);
            }
        }

        if (in_array(self::CHANNEL_PUSH, $resolvedChannels, true)) {
            $pushResult = $this->sendPush($user, $title, $message, $data, $actor?->id);
            $summary['push'] += $pushResult['sent'];
            $summary['failed'] += $pushResult['failed'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createInAppNotification(
        User $user,
        string $category,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $imageUrl = null,
        array $data = [],
        ?User $actor = null,
    ): UserNotification {
        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'image_url' => $imageUrl,
            'data' => $data,
            'created_by_user_id' => $actor?->id,
        ]);

        $this->logDispatch($user->id, self::CHANNEL_IN_APP, 'SENT', $title, $message, ['notification_id' => $notification->id], null, $actor?->id);

        return $notification;
    }

    /**
     * @return array{sent:int,failed:int}
     */
    private function sendPush(User $user, string $title, string $message, array $data, ?string $actorId = null): array
    {
        $sent = 0;
        $failed = 0;

        $tokens = $user->deviceTokens()->get();

        foreach ($tokens as $token) {
            try {
                if ($token->platform === UserDeviceToken::PLATFORM_ANDROID) {
                    $response = $this->firebasePushService->send($token->token, $title, $message, $data);
                } elseif ($token->platform === UserDeviceToken::PLATFORM_IOS) {
                    $response = $this->apnPushService->send($token->token, $title, $message, $data);
                } else {
                    continue;
                }

                $token->forceFill(['last_used_at' => now()])->save();
                $sent++;
                $this->logDispatch($user->id, self::CHANNEL_PUSH, 'SENT', $title, $message, $response, null, $actorId);
            } catch (Throwable $exception) {
                $failed++;
                $this->logDispatch($user->id, self::CHANNEL_PUSH, 'FAILED', $title, $message, null, $exception->getMessage(), $actorId);
                Log::warning('Notification push delivery failed', [
                    'user_id' => $user->id,
                    'platform' => $token->platform,
                    'token_id' => $token->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * @param list<string> $channels
     * @return list<string>
     */
    private function resolveChannels(array $channels): array
    {
        if (in_array('all', $channels, true)) {
            return [self::CHANNEL_IN_APP, self::CHANNEL_EMAIL, self::CHANNEL_PUSH];
        }

        return array_values(array_unique(array_filter($channels, static fn (string $channel): bool => in_array($channel, [
            self::CHANNEL_IN_APP,
            self::CHANNEL_EMAIL,
            self::CHANNEL_PUSH,
        ], true))));
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function logDispatch(
        ?string $userId,
        string $channel,
        string $status,
        string $title,
        string $message,
        ?array $response = null,
        ?string $error = null,
        ?string $createdByUserId = null,
    ): void {
        NotificationDispatchLog::query()->create([
            'user_id' => $userId,
            'channel' => $channel,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'response' => $response,
            'error' => $error,
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}
