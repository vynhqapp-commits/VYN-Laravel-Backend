<!DOCTYPE html>
@php
    $l = in_array($mailLocale, ['en', 'ar', 'fr']) ? $mailLocale : 'en';
    $isRtl = $l === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';

    /** @var \App\Models\Appointment $appointment */
    $service = $appointment->services->first()?->service;
    $window = $reminderType === '24h' ? '24 hours' : '1 hour';

    $strings = [
        'en' => [
            'title' => 'Appointment reminder',
            'greeting' => "this is a reminder that your appointment starts in {$window}.",
            'service' => 'Service',
            'location' => 'Salon / Location',
            'staff' => 'Staff',
            'date' => 'Date',
            'time' => 'Time',
            'footer' => 'Please arrive a few minutes early.',
        ],
        'ar' => [
            'title' => 'تذكير بالموعد',
            'greeting' => "هذا تذكير بأن موعدك يبدأ خلال {$window}.",
            'service' => 'الخدمة',
            'location' => 'الصالون / الموقع',
            'staff' => 'الموظف',
            'date' => 'التاريخ',
            'time' => 'الوقت',
            'footer' => 'يرجى الحضور قبل الموعد ببضع دقائق.',
        ],
        'fr' => [
            'title' => 'Rappel de rendez-vous',
            'greeting' => "ceci est un rappel: votre rendez-vous commence dans {$window}.",
            'service' => 'Service',
            'location' => 'Salon / Lieu',
            'staff' => 'Personnel',
            'date' => 'Date',
            'time' => 'Heure',
            'footer' => 'Merci d’arriver quelques minutes en avance.',
        ],
    ];

    $s = $strings[$l];
    $customerName =
        $appointment->customer?->name ?? ($l === 'ar' ? 'عزيزي العميل' : ($l === 'fr' ? 'cher client' : 'there'));
@endphp
<html lang="{{ $l }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $s['title'] }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; margin: 0; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #eee1d5;">
        <h1 style="font-size: 18px; margin: 0 0 10px; color: #2c1810;">{{ $s['title'] }}</h1>
        <p style="margin: 0 0 14px; color: #444;">{{ $l === 'ar' ? 'مرحباً ' : 'Hi ' }}{{ $customerName }}{{ $l === 'ar' ? '،' : ',' }} {{ $s['greeting'] }}</p>

        <div style="background: #faf8f5; border-radius: 10px; padding: 14px; border: 1px solid #eee1d5;">
            <p style="margin: 0 0 8px;"><strong>{{ $s['service'] }}:</strong> {{ $service?->name ?? '—' }}</p>
            <p style="margin: 0 0 8px;"><strong>{{ $s['location'] }}:</strong> {{ $appointment->branch?->name ?? '—' }}</p>
            <p style="margin: 0 0 8px;"><strong>{{ $s['staff'] }}:</strong> {{ $appointment->staff?->name ?? '—' }}</p>
            <p style="margin: 0 0 8px;"><strong>{{ $s['date'] }}:</strong> {{ optional($appointment->starts_at)->format('D, M j, Y') }}</p>
            <p style="margin: 0;"><strong>{{ $s['time'] }}:</strong> {{ optional($appointment->starts_at)->format('H:i') }} – {{ optional($appointment->ends_at)->format('H:i') }}</p>
        </div>

        <p style="margin: 14px 0 0; color: #777; font-size: 12px;">{{ $s['footer'] }}</p>
    </div>
</body>
</html>
