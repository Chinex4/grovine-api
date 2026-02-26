<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bucket' => ['nullable', 'in:ongoing,completed,canceled,all'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        $bucket = $validated['bucket'] ?? 'all';
        $viewer = $request->user();

        $query = Order::query()
            ->with(['items', 'user:id,name,email,role'])
            ->orderByDesc('created_at');

        if ($viewer->hasRole('admin', 'chef')) {
            if (! empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }
        } else {
            $query->where('user_id', $viewer->id);
        }

        $this->applyBucketFilter($query, $bucket);

        $orders = $query->get()->map(function (Order $order): array {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'bucket' => $order->bucket(),
                'subtotal' => $order->subtotal,
                'total' => $order->total,
                'item_count' => $order->items->sum('quantity'),
                'items' => $order->items,
                'user' => $order->user,
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'message' => 'Orders fetched successfully.',
            'data' => $orders,
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        if (! $this->canReadOrder($request, $order)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $order->loadMissing(['items', 'payments', 'user:id,name,email,role']);

        return response()->json([
            'message' => 'Order fetched successfully.',
            'data' => $order,
        ]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        if (! $this->canReadOrder($request, $order)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:AWAITING_PAYMENT,IN_TRANSIT,IN_TRANSIIT,DELIVERED,DELEIVERED,CANCELED'],
        ]);

        $targetStatus = match ($validated['status']) {
            'IN_TRANSIIT' => Order::STATUS_IN_TRANSIT,
            'DELEIVERED' => Order::STATUS_DELIVERED,
            default => $validated['status'],
        };
        $user = $request->user();

        if (in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELED], true)) {
            return response()->json([
                'message' => 'Finalized orders cannot be updated.',
            ], 422);
        }

        if ($user->hasRole('admin', 'chef')) {
            if ($targetStatus === Order::STATUS_IN_TRANSIT && $order->payment_status !== Order::PAYMENT_PAID) {
                return response()->json([
                    'message' => 'Only paid orders can move to IN_TRANSIT.',
                ], 422);
            }

            $order->update([
                'status' => $targetStatus,
                'canceled_at' => $targetStatus === Order::STATUS_CANCELED ? now() : null,
            ]);

            $this->sendOrderStatusNotification($order->fresh());

            return response()->json([
                'message' => 'Order status updated successfully.',
                'data' => $order->fresh(['items', 'payments']),
            ]);
        }

        if ($order->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $allowedTransitions = [
            Order::STATUS_AWAITING_PAYMENT => [Order::STATUS_CANCELED],
            Order::STATUS_IN_TRANSIT => [Order::STATUS_DELIVERED],
        ];

        if (! in_array($targetStatus, $allowedTransitions[$order->status] ?? [], true)) {
            return response()->json([
                'message' => 'You are not allowed to set this status for the current order state.',
            ], 422);
        }

        $order->update([
            'status' => $targetStatus,
            'canceled_at' => $targetStatus === Order::STATUS_CANCELED ? now() : null,
        ]);

        $this->sendOrderStatusNotification($order->fresh());

        return response()->json([
            'message' => 'Order status updated successfully.',
            'data' => $order->fresh(['items', 'payments']),
        ]);
    }

    private function canReadOrder(Request $request, Order $order): bool
    {
        $user = $request->user();

        if ($user->hasRole('admin', 'chef')) {
            return true;
        }

        return $order->user_id === $user->id;
    }

    private function applyBucketFilter(Builder $query, string $bucket): void
    {
        if ($bucket === 'ongoing') {
            $query->whereIn('status', [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_IN_TRANSIT]);
        }

        if ($bucket === 'completed') {
            $query->where('status', Order::STATUS_DELIVERED);
        }

        if ($bucket === 'canceled') {
            $query->where('status', Order::STATUS_CANCELED);
        }
    }

    private function sendOrderStatusNotification(Order $order): void
    {
        $user = $order->user()->first();

        if (! $user) {
            return;
        }

        $message = match ($order->status) {
            Order::STATUS_IN_TRANSIT => 'Order '.$order->order_number.' is now in transit.',
            Order::STATUS_DELIVERED => 'Order '.$order->order_number.' has been delivered.',
            Order::STATUS_CANCELED => 'Order '.$order->order_number.' has been canceled.',
            default => 'Order '.$order->order_number.' status was updated to '.$order->status.'.',
        };

        $this->notificationService->sendAccountActivity(
            user: $user,
            title: 'Order status updated',
            message: $message,
            data: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
            ],
            channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_PUSH],
        );
    }
}
