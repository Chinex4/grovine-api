<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OtpService;
use App\Services\UsernameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly JwtService $jwtService,
        private readonly UsernameService $usernameService,
    ) {
    }

    public function signup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'referral_code' => ['nullable', 'string', 'max:60'],
        ]);

        $email = strtolower($validated['email']);

        $user = User::query()->where('email', $email)->first();

        if ($user && $user->email_verified_at) {
            return response()->json([
                'message' => 'An account already exists with this email.',
            ], 422);
        }

        if ($user) {
            $user->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $user->phone,
                'referral_code' => $validated['referral_code'] ?? $user->referral_code,
            ]);
        } else {
            $user = User::query()->create([
                'name' => $validated['name'],
                'username' => $this->usernameService->generate($validated['name']),
                'email' => $email,
                'phone' => $validated['phone'] ?? null,
                'referral_code' => $validated['referral_code'] ?? null,
                'password' => null,
            ]);
        }

        $otpData = $this->otpService->issue($user, 'signup');

        return response()->json([
            'message' => 'OTP sent successfully.',
            'data' => $this->otpResponsePayload($otpData['otp']->expires_at->toIso8601String(), $otpData['plain_code']),
        ], 201);
    }

    public function verifySignupOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:5'],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found for this email.',
            ], 404);
        }

        $verified = $this->otpService->verify($user, 'signup', $validated['otp']);

        if (! $verified) {
            return response()->json([
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        if (! $user->email_verified_at) {
            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        $token = $this->jwtService->issueForUser($user);

        return response()->json([
            'message' => 'Account verified successfully.',
            'data' => $this->authResponsePayload($user, $token['token'], $token['expires_at']),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found for this email.',
            ], 404);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your account before login.',
            ], 403);
        }

        $otpData = $this->otpService->issue($user, 'login');

        return response()->json([
            'message' => 'OTP sent successfully.',
            'data' => $this->otpResponsePayload($otpData['otp']->expires_at->toIso8601String(), $otpData['plain_code']),
        ]);
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:5'],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found for this email.',
            ], 404);
        }

        $verified = $this->otpService->verify($user, 'login', $validated['otp']);

        if (! $verified) {
            return response()->json([
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        $token = $this->jwtService->issueForUser($user);

        return response()->json([
            'message' => 'Login successful.',
            'data' => $this->authResponsePayload($user, $token['token'], $token['expires_at']),
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'purpose' => ['required', 'in:signup,login'],
        ]);

        $purpose = $validated['purpose'];
        $user = User::query()->where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found for this email.',
            ], 404);
        }

        if ($purpose === 'signup' && $user->email_verified_at) {
            return response()->json([
                'message' => 'Account already verified. Please login instead.',
            ], 422);
        }

        if ($purpose === 'login' && ! $user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your account before login.',
            ], 403);
        }

        $otpData = $this->otpService->issue($user, $purpose);

        return response()->json([
            'message' => 'OTP resent successfully.',
            'data' => $this->otpResponsePayload($otpData['otp']->expires_at->toIso8601String(), $otpData['plain_code']),
        ]);
    }

    private function otpResponsePayload(string $expiresAt, string $otpCode): array
    {
        $payload = [
            'otp_expires_at' => $expiresAt,
            'otp_length' => (int) config('otp.length', 5),
        ];

        if (config('otp.debug_expose_code')) {
            $payload['otp'] = $otpCode;
        }

        return $payload;
    }

    private function authResponsePayload(User $user, string $token, string $expiresAt): array
    {
        $user->loadMissing(['favoriteFoods:id,name,slug', 'cuisineRegions:id,name,slug']);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'onboarding_completed' => $user->onboarding_completed,
                'favorite_foods' => $user->favoriteFoods,
                'cuisine_regions' => $user->cuisineRegions,
            ],
        ];
    }
}
