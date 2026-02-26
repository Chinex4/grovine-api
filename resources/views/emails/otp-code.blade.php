<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grovine OTP Code</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <h2 style="margin-bottom: 12px;">Your Grovine verification code</h2>
    <p style="margin-bottom: 16px;">
        Use this code to complete your {{ $purpose === 'login' ? 'login' : 'signup' }}:
    </p>

    <p style="font-size: 28px; letter-spacing: 8px; font-weight: 700; margin: 18px 0; color: #16a34a;">
        {{ $code }}
    </p>

    <p style="margin-bottom: 16px;">
        This code expires at {{ $expiresAt->format('g:i A') }}.
    </p>

    <p style="color: #6b7280; font-size: 12px;">
        If you did not request this code, you can ignore this email.
    </p>
</body>
</html>
