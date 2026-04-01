<!DOCTYPE html>
@php
    $isRtl = $mailLocale === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';

    $headings = [
        'en' => [
            'register' => 'Verify your email',
            'reset_password' => 'Reset your password',
            'login' => 'Sign in to your account',
        ],
        'ar' => [
            'register' => 'تحقق من بريدك الإلكتروني',
            'reset_password' => 'إعادة تعيين كلمة المرور',
            'login' => 'تسجيل الدخول إلى حسابك',
        ],
        'fr' => [
            'register' => 'Vérifiez votre e-mail',
            'reset_password' => 'Réinitialisez votre mot de passe',
            'login' => 'Connectez-vous à votre compte',
        ],
    ];
    $bodies = [
        'en' => [
            'register' => 'Use the code below to complete your registration:',
            'reset_password' => 'Use the code below to reset your password:',
            'login' => 'Use the code below to sign in:',
        ],
        'ar' => [
            'register' => 'استخدم الرمز أدناه لإتمام تسجيلك:',
            'reset_password' => 'استخدم الرمز أدناه لإعادة تعيين كلمة مرورك:',
            'login' => 'استخدم الرمز أدناه لتسجيل الدخول:',
        ],
        'fr' => [
            'register' => 'Utilisez le code ci-dessous pour finaliser votre inscription :',
            'reset_password' => 'Utilisez le code ci-dessous pour réinitialiser votre mot de passe :',
            'login' => 'Utilisez le code ci-dessous pour vous connecter :',
        ],
    ];
    $expiries = [
        'en' => "This code expires in {$expiresInMinutes} minutes. Do not share it with anyone.",
        'ar' => "ينتهي صلاحية هذا الرمز خلال {$expiresInMinutes} دقائق. لا تشاركه مع أحد.",
        'fr' => "Ce code expire dans {$expiresInMinutes} minutes. Ne le partagez avec personne.",
    ];
    $footers = [
        'en' => "If you didn't request this code, you can safely ignore this email.",
        'ar' => 'إذا لم تطلب هذا الرمز، يمكنك تجاهل هذا البريد الإلكتروني بأمان.',
        'fr' => "Si vous n'avez pas demandé ce code, vous pouvez ignorer cet e-mail.",
    ];

    $l = in_array($mailLocale, ['en', 'ar', 'fr']) ? $mailLocale : 'en';
    $heading = $headings[$l][$purpose] ?? $headings['en']['login'];
    $body = $bodies[$l][$purpose] ?? $bodies['en']['login'];
    $expiry = $expiries[$l] ?? $expiries['en'];
    $footer = $footers[$l] ?? $footers['en'];
@endphp
<html lang="{{ $l }}" dir="{{ $dir }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            direction: {{ $dir }};
        }

        .container {
            max-width: 480px;
            margin: 24px auto;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .code {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 2px;
            color: #2c1810;
            background: #f8f4f0;
            padding: 16px 24px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }

        .footer {
            margin-top: 24px;
            font-size: 12px;
            color: #888;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 8px;
            color: #2c1810;
        }

        p {
            margin: 0 0 12px;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>{{ $heading }}</h1>
        <p>{{ $body }}</p>
        <div class="code">{{ $code }}</div>
        <p>{{ $expiry }}</p>
        <div class="footer">{{ $footer }}</div>
    </div>
</body>

</html>
