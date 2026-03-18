<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $admin = User::query()
            ->where('email', strtolower((string) $validated['email']))
            ->where('role', User::ROLE_ADMIN)
            ->first();

        if (! $admin || ! $admin->password || ! Hash::check($validated['password'], $admin->password)) {
            return response()->json([
                'message' => 'Invalid admin credentials.',
            ], 401);
        }

        if ($blocked = $this->blockedAccountResponse($admin)) {
            return $blocked;
        }

        $token = $this->jwtService->issueForUser($admin);
        $admin->loadMissing(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description']);

        return response()->json([
            'message' => 'Admin login successful.',
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token['token'],
                'expires_at' => $token['expires_at'],
                'user' => (new UserResource($admin))->resolve(),
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        if (! $admin->password || ! Hash::check($validated['current_password'], $admin->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => ['Current password is incorrect.'],
                ],
            ], 422);
        }

        $admin->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Admin password changed successfully.',
            'data' => [
                'updated_at' => $admin->fresh()?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function blockedAccountResponse(User $user): ?JsonResponse
    {
        $user->normalizeAccountStatus();

        if ($user->effectiveAccountStatus() === User::ACCOUNT_STATUS_BANNED) {
            return response()->json([
                'message' => 'This admin account has been banned.',
            ], 403);
        }

        if ($user->effectiveAccountStatus() === User::ACCOUNT_STATUS_SUSPENDED) {
            $message = 'This admin account is currently suspended.';

            if ($user->suspended_until) {
                $message = 'This admin account is suspended until '.$user->suspended_until->toDayDateTimeString().'.';
            }

            return response()->json([
                'message' => $message,
            ], 403);
        }

        return null;
    }
}
