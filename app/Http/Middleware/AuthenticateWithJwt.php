<?php

namespace App\Http\Middleware;

use App\Models\UserDailyActivity;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateWithJwt
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return new JsonResponse([
                'message' => 'Unauthorized.',
            ], 401);
        }

        try {
            $payload = $this->jwtService->decode($token);
        } catch (Throwable) {
            return new JsonResponse([
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $userId = $payload['sub'] ?? null;

        if (! is_string($userId)) {
            return new JsonResponse([
                'message' => 'Invalid token subject.',
            ], 401);
        }

        $user = User::query()->find($userId);

        if (! $user) {
            return new JsonResponse([
                'message' => 'User not found for token.',
            ], 401);
        }

        $user->normalizeAccountStatus();

        if ($user->effectiveAccountStatus() === User::ACCOUNT_STATUS_BANNED) {
            return new JsonResponse([
                'message' => 'Your account has been banned by an administrator.',
            ], 403);
        }

        if ($user->effectiveAccountStatus() === User::ACCOUNT_STATUS_SUSPENDED) {
            $message = 'Your account is currently suspended.';

            if ($user->suspended_until) {
                $message = 'Your account is suspended until '.$user->suspended_until->toDayDateTimeString().'.';
            }

            return new JsonResponse([
                'message' => $message,
            ], 403);
        }

        $this->trackActivity($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function trackActivity(User $user): void
    {
        $now = now();
        $shouldWrite = ! $user->last_seen_at || $user->last_seen_at->lte($now->copy()->subMinutes(5));

        if (! $shouldWrite) {
            return;
        }

        $user->forceFill([
            'last_seen_at' => $now,
        ])->saveQuietly();

        $activity = UserDailyActivity::query()->firstOrNew([
            'user_id' => $user->id,
            'activity_date' => $now->toDateString(),
        ]);

        $activity->hits = ((int) $activity->hits) + 1;
        $activity->last_seen_at = $now;
        $activity->save();

        $user->last_seen_at = $now;
    }
}
