<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'in:ACCOUNT_ACTIVITY,SYSTEM,ADMIN'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:in_app,email,push,all'],
            'audience' => ['required', 'in:all,users,roles'],
            'user_ids' => ['required_if:audience,users', 'array'],
            'user_ids.*' => ['uuid', 'exists:users,id'],
            'roles' => ['required_if:audience,roles', 'array'],
            'roles.*' => ['in:user,chef,admin'],
            'action_url' => ['nullable', 'string', 'max:2048'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'data' => ['nullable', 'array'],
        ]);

        $users = match ($validated['audience']) {
            'all' => User::query()->get(),
            'users' => User::query()->whereIn('id', $validated['user_ids'] ?? [])->get(),
            'roles' => User::query()->whereIn('role', $validated['roles'] ?? [])->get(),
            default => collect(),
        };

        $summary = $this->notificationService->sendAdminNotification(
            users: $users,
            payload: [
                'title' => $validated['title'],
                'message' => $validated['message'],
                'category' => $validated['category'] ?? NotificationService::CATEGORY_ADMIN,
                'action_url' => $validated['action_url'] ?? null,
                'image_url' => $validated['image_url'] ?? null,
                'data' => $validated['data'] ?? [],
            ],
            channels: $validated['channels'],
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Notification dispatch completed.',
            'data' => [
                'recipient_count' => $users->count(),
                'summary' => $summary,
            ],
        ]);
    }
}
