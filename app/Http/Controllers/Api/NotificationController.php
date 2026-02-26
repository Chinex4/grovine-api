<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'in:ACCOUNT_ACTIVITY,SYSTEM,ADMIN'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);

        $query = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if (! empty($validated['unread_only'])) {
            $query->where('is_read', false);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        return response()->json([
            'message' => 'Notifications fetched successfully.',
            'data' => $query->limit($limit)->get(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'message' => 'Unread notification count fetched successfully.',
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    public function markAsRead(Request $request, UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (! $notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    public function registerDeviceToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'token' => ['required', 'string', 'max:512'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $deviceToken = UserDeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device token registered successfully.',
            'data' => $deviceToken,
        ], 201);
    }

    public function removeDeviceToken(Request $request, UserDeviceToken $deviceToken): JsonResponse
    {
        if ($deviceToken->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $deviceToken->delete();

        return response()->json([
            'message' => 'Device token removed successfully.',
        ]);
    }
}
