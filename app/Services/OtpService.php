<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OtpService
{
    /**
     * @return array{otp:OtpCode,plain_code:string,delivery_channel:string,uses_test_code:bool}
     */
    public function issue(User $user, string $purpose): array
    {
        $latest = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        $throttleSeconds = (int) config('otp.resend_throttle_seconds', 60);

        if ($latest && $latest->created_at->gt(now()->subSeconds($throttleSeconds))) {
            $waitSeconds = now()->diffInSeconds($latest->created_at->addSeconds($throttleSeconds));

            throw ValidationException::withMessages([
                'otp' => ["Please wait {$waitSeconds} seconds before requesting another code."],
            ]);
        }

        $usesTestCode = $this->shouldUseTestLoginCode($user, $purpose);
        $plainCode = $usesTestCode ? $this->getTestLoginCode() : $this->generateCode();

        $otp = OtpCode::query()->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $plainCode),
            'expires_at' => now()->addMinutes((int) config('otp.expiry_minutes', 10)),
        ]);

        if (! $usesTestCode) {
            try {
                Mail::to($user->email)->send(new OtpCodeMail(
                    code: $plainCode,
                    purpose: $purpose,
                    expiresAt: $otp->expires_at,
                ));
            } catch (Throwable $exception) {
                $otp->delete();

                Log::error('Failed to send Grovine OTP email', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'purpose' => $purpose,
                    'error' => $exception->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'otp' => ['Unable to send OTP email right now. Please try again.'],
                ]);
            }
        }

        Log::info('Grovine OTP generated', [
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => $purpose,
            'otp' => $plainCode,
            'delivery_channel' => $usesTestCode ? 'fixed_test_code' : 'email',
            'expires_at' => $otp->expires_at->toIso8601String(),
        ]);

        return [
            'otp' => $otp,
            'plain_code' => $plainCode,
            'delivery_channel' => $usesTestCode ? 'fixed_test_code' : 'email',
            'uses_test_code' => $usesTestCode,
        ];
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $otp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            return false;
        }

        $hash = hash('sha256', $code);

        if (! hash_equals($otp->code_hash, $hash)) {
            return false;
        }

        $otp->update([
            'verified_at' => now(),
        ]);

        return true;
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.length', 5);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function shouldUseTestLoginCode(User $user, string $purpose): bool
    {
        if ($purpose !== 'login' || ! (bool) config('otp.test_login.enabled', false)) {
            return false;
        }

        $configuredEmail = strtolower(trim((string) config('otp.test_login.email', '')));

        if ($configuredEmail === '' || strtolower($user->email) !== $configuredEmail) {
            return false;
        }

        $configuredCode = $this->getTestLoginCode();

        return $configuredCode !== '';
    }

    private function getTestLoginCode(): string
    {
        $code = trim((string) config('otp.test_login.code', ''));
        $length = (int) config('otp.length', 5);

        if ($code === '' || strlen($code) !== $length || ! ctype_digit($code)) {
            return '';
        }

        return $code;
    }
}
