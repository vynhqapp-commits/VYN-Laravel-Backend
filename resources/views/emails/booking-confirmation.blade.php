<!DOCTYPE html>
@php
    $l = in_array($locale, ['en', 'ar', 'fr']) ? $locale : 'en';
    $isRtl = $l === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';

    /** @var \App\Models\Appointment $appointment */
    $service = $appointment->services->first()?->service;

    $strings = [
        'en' => [
            'title' => 'Your booking is confirmed',
            'greeting' => 'your appointment has been booked successfully.',
            'service' => 'Service',
            'location' => 'Salon / Location',
            'address' => 'Address',
            'staff' => 'Staff',
            'date' => 'Date',
            'time' => 'Time',
            'status' => 'Status',
            'footer' => "If you didn't request this booking, please contact the salon.",
        ],
        'ar' => [
            'title' => 'تم تأكيد حجزك',
            'greeting' => 'تم حجز موعدك بنجاح.',
            'service' => 'الخدمة',
            'location' => 'الصالون / الموقع',
            'address' => 'العنوان',
            'staff' => 'الموظف',
            'date' => 'التاريخ',
            'time' => 'الوقت',
            'status' => 'الحالة',
            'footer' => 'إذا لم تطلب هذا الحجز، يرجى التواصل مع الصالون.',
        ],
        'fr' => [
            'title' => 'Votre réservation est confirmée',
            'greeting' => 'votre rendez-vous a été réservé avec succès.',
            'service' => 'Service',
            'location' => 'Salon / Lieu',
            'address' => 'Adresse',
            'staff' => 'Personnel',
            'date' => 'Date',
            'time' => 'Heure',
            'status' => 'Statut',
            'footer' => "Si vous n'avez pas demandé cette réservation, veuillez contacter le salon.",
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
            max-width: 560px;
            margin: 24px auto;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        h1 {
            font-size: 18px;
            margin: 0 0 12px;
            color: #2c1810;
        }

        p {
            margin: 0 0 12px;
        }

        .card {
            background: #faf8f5;
            border: 1px solid #eee1d5;
            border-radius: 10px;
            padding: 16px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 6px 0;
        }

        .label {
            color: #7b6f66;
            font-size: 13px;
        }

        .value {
            color: #2c1810;
            font-weight: 600;
            font-size: 13px;
            text-align: {{ $isRtl ? 'left' : 'right' }};
        }

        .footer {
            margin-top: 18px;
            font-size: 12px;
            color: #888;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>{{ $s['title'] }}</h1>
        <p>{{ $l === 'ar' ? 'مرحباً ' : 'Hi ' }}{{ $customerName }}{{ $l === 'ar' ? '،' : ',' }} {{ $s['greeting'] }}
        </p>

        <div class="card">
            <div class="row">
                <span class="label">{{ $s['service'] }}</span>
                <span class="value">{{ $service?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="label">{{ $s['location'] }}</span>
                <span class="value">{{ $appointment->branch?->name ?? '—' }}</span>
            </div>
            @if (!empty($appointment->branch?->address))
                <div class="row">
                    <span class="label">{{ $s['address'] }}</span>
                    <span class="value">{{ $appointment->branch->address }}</span>
                </div>
            @endif
            <div class="row">
                <span class="label">{{ $s['staff'] }}</span>
                <span class="value">{{ $appointment->staff?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="label">{{ $s['date'] }}</span>
                <span class="value">{{ optional($appointment->starts_at)->format('D, M j, Y') }}</span>
            </div>
            <div class="row">
                <span class="label">{{ $s['time'] }}</span>
                <span class="value">
                    {{ optional($appointment->starts_at)->format('H:i') }}
                    –
                    {{ optional($appointment->ends_at)->format('H:i') }}
                </span>
            </div>
            <div class="row">
                <span class="label">{{ $s['status'] }}</span>
                <span class="value">{{ $appointment->status }}</span>
            </div>
        </div>

        <div class="footer">{{ $s['footer'] }}</div>
    </div>
</body>

</html>
