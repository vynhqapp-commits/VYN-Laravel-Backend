<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your verification code</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 480px; margin: 24px auto; padding: 24px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .code { font-size: 28px; font-weight: 700; letter-spacing: 2px; color: #2c1810; background: #f8f4f0; padding: 16px 24px; border-radius: 8px; text-align: center; margin: 20px 0; }
        .footer { margin-top: 24px; font-size: 12px; color: #888; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #2c1810; }
        p { margin: 0 0 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            @if($purpose === 'register')
                Verify your email
            @elseif($purpose === 'reset_password')
                Reset your password
            @else
                Sign in to your account
            @endif
        </h1>
        <p>
            @if($purpose === 'register')
                Use the code below to complete your registration:
            @elseif($purpose === 'reset_password')
                Use the code below to reset your password:
            @else
                Use the code below to sign in:
            @endif
        </p>
        <div class="code">{{ $code }}</div>
        <p>This code expires in {{ $expiresInMinutes }} minutes. Do not share it with anyone.</p>
        <div class="footer">
            If you didn't request this code, you can safely ignore this email.
        </div>
    </div>
</body>
</html>
