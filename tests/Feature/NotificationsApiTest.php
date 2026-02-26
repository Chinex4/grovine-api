<?php

namespace Tests\Feature;

use App\Mail\GenericNotificationMail;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_fetch_and_mark_notifications_and_manage_device_tokens(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $token = app(JwtService::class)->issueForUser($user)['token'];

        $first = UserNotification::query()->create([
            'user_id' => $user->id,
            'category' => 'SYSTEM',
            'title' => 'Welcome',
            'message' => 'Welcome to Grovine.',
            'is_read' => false,
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'category' => 'ACCOUNT_ACTIVITY',
            'title' => 'Order update',
            'message' => 'Order in transit.',
            'is_read' => false,
        ]);

        UserNotification::query()->create([
            'user_id' => $otherUser->id,
            'category' => 'SYSTEM',
            'title' => 'Hidden',
            'message' => 'Should not be visible.',
            'is_read' => false,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('message', 'Notifications fetched successfully.')
            ->assertJsonCount(2, 'data');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/notifications/'.$first->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read.');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $register = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/notifications/device-tokens', [
                'platform' => 'android',
                'token' => 'android-device-token-1',
                'device_name' => 'Pixel',
            ])->assertCreated();

        $deviceTokenId = (string) $register->json('data.id');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson('/api/notifications/device-tokens/'.$deviceTokenId)
            ->assertOk()
            ->assertJsonPath('message', 'Device token removed successfully.');
    }

    public function test_admin_can_send_notification_on_all_channels(): void
    {
        Mail::fake();
        config()->set('notification_channels.firebase.server_key', 'firebase_test_server_key');

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'success' => 1,
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ]);
        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $adminToken = app(JwtService::class)->issueForUser($admin)['token'];

        $recipient->deviceTokens()->create([
            'platform' => 'android',
            'token' => 'recipient-android-token',
            'device_name' => 'Galaxy',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
        ])->postJson('/api/admin/notifications/send', [
            'title' => 'Fast Sale',
            'message' => 'Fresh products now discounted.',
            'category' => 'ADMIN',
            'channels' => ['all'],
            'audience' => 'users',
            'user_ids' => [$recipient->id],
            'action_url' => 'https://app.grovine.ng/offers',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Notification dispatch completed.')
            ->assertJsonPath('data.recipient_count', 1)
            ->assertJsonPath('data.summary.in_app', 1)
            ->assertJsonPath('data.summary.email', 1)
            ->assertJsonPath('data.summary.push', 1)
            ->assertJsonPath('data.summary.failed', 0);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $recipient->id,
            'title' => 'Fast Sale',
            'category' => 'ADMIN',
        ]);

        Mail::assertSent(GenericNotificationMail::class, 1);
        Http::assertSentCount(1);

        $this->assertDatabaseHas('notification_dispatch_logs', [
            'user_id' => $recipient->id,
            'channel' => 'in_app',
            'status' => 'SENT',
            'title' => 'Fast Sale',
        ]);
    }

    public function test_order_status_update_creates_account_activity_notification(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ]);
        $buyer = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $adminToken = app(JwtService::class)->issueForUser($admin)['token'];
        $buyerToken = app(JwtService::class)->issueForUser($buyer)['token'];

        $order = Order::query()->create([
            'order_number' => 'ORD-NTF-10001',
            'user_id' => $buyer->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_method' => Order::PAYMENT_METHOD_PAYSTACK,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 1000,
            'delivery_fee' => 0,
            'service_fee' => 0,
            'affiliate_fee' => 0,
            'total' => 1000,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->patchJson('/api/orders/'.$order->id.'/status', [
                'status' => Order::STATUS_IN_TRANSIT,
            ])
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$buyerToken])
            ->getJson('/api/notifications?category=ACCOUNT_ACTIVITY')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Order status updated')
            ->assertJsonPath('data.0.category', 'ACCOUNT_ACTIVITY');
    }
}

