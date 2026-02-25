<?php

namespace App\Http\Middleware;

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

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
