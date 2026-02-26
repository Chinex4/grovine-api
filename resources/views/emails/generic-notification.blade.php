<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <h2 style="margin-bottom: 12px;">{{ $title }}</h2>
    <p style="margin-bottom: 16px;">{{ $messageText }}</p>

    @if($actionUrl)
        <p style="margin-bottom: 16px;">
            <a href="{{ $actionUrl }}" style="background: #4caf50; color: white; text-decoration: none; padding: 10px 14px; border-radius: 6px; display: inline-block;">
                View Details
            </a>
        </p>
    @endif

    <p style="color: #6b7280; font-size: 12px;">This notification was sent by Grovine.</p>
</body>
</html>
