# Play Store Test Login

This document defines the seeded reviewer account flow for Play Store testing.

## Purpose

Play Store reviewers need a deterministic login path that does not depend on receiving an OTP email. This implementation provides:

- a seeded, verified user account
- a fixed OTP for that account on the login flow only
- response metadata so the frontend can tell when the login request is using the reviewer path

## Required Environment

Set these values in the environment used for Play Store review:

```env
OTP_TEST_LOGIN_ENABLED=true
OTP_TEST_LOGIN_EMAIL=playstore.reviewer@grovine.ng
OTP_TEST_LOGIN_NAME="Play Store Reviewer"
OTP_TEST_LOGIN_CODE=55555
```

Then run the seeder:

```bash
php artisan db:seed --class=Database\\Seeders\\PlayStoreTestAccountSeeder
```

If you run the full database seeder, `PlayStoreTestAccountSeeder` is included automatically.

## Seeded Account Behavior

When `OTP_TEST_LOGIN_ENABLED=true`, the seeder creates or updates a verified user with:

- `email = OTP_TEST_LOGIN_EMAIL`
- `name = OTP_TEST_LOGIN_NAME`
- `role = user`
- `onboarding_completed = true`

For that account only:

- `POST /api/auth/login` creates an OTP using `OTP_TEST_LOGIN_CODE`
- the OTP is not emailed
- `POST /api/auth/verify-login-otp` accepts that fixed OTP

Normal users continue to receive email OTPs.

## Login API Contract

### 1. Request OTP

`POST /api/auth/login`

Request:

```json
{
  "email": "playstore.reviewer@grovine.ng"
}
```

Success response:

```json
{
  "message": "OTP sent successfully.",
  "data": {
    "otp_expires_at": "2026-04-13T20:05:00Z",
    "otp_length": 5,
    "otp_delivery_channel": "fixed_test_code",
    "uses_test_otp": true
  }
}
```

For regular users the response is the same shape, but:

- `otp_delivery_channel = "email"`
- `uses_test_otp = false`

### 2. Verify OTP

`POST /api/auth/verify-login-otp`

Request:

```json
{
  "email": "playstore.reviewer@grovine.ng",
  "otp": "55555"
}
```

Success response:

- `message = "Login successful."`
- `data.access_token` contains the JWT
- `data.user` contains the authenticated user profile

## Frontend Requirements

The frontend must do the following to ensure the Play Store test account works reliably:

1. Trigger the normal login flow first by calling `POST /api/auth/login` with the test email.
2. Read `data.uses_test_otp` or `data.otp_delivery_channel`.
3. If `uses_test_otp=true`, do not show "check your email for the OTP" as the only instruction.
4. In the Play Store review build, provide the reviewer email and fixed OTP in the UI or in a reviewer-only helper action so the reviewer can complete login without mailbox access.
5. Submit the fixed OTP through the normal `POST /api/auth/verify-login-otp` endpoint. Do not bypass OTP verification on the client.
6. Respect `otp_expires_at`. If the OTP expires, call `POST /api/auth/login` again to issue a fresh login challenge before retrying verification.
7. Handle resend/login throttling. If the API returns a validation error like `Please wait X seconds before requesting another code.`, keep the current OTP entry screen and retry after the cooldown instead of restarting the flow aggressively.

## Recommended Frontend UX

- Add a "Use Play Store test account" button in review builds.
- Autofill the seeded email when that button is tapped.
- After `/api/auth/login`, if `uses_test_otp=true`, prefill or clearly display the fixed OTP entry path for reviewers.
- Keep the rest of the login flow identical to a normal OTP login so the same backend endpoints are exercised.

## Notes

- The fixed OTP only applies to the configured reviewer account and only for `purpose=login`.
- Signup OTPs remain random.
- This flow is controlled by environment configuration and can be disabled by setting `OTP_TEST_LOGIN_ENABLED=false`.
