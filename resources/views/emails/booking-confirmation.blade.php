<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking confirmed</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 560px; margin: 24px auto; padding: 24px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        h1 { font-size: 18px; margin: 0 0 12px; color: #2c1810; }
        p { margin: 0 0 12px; }
        .card { background: #faf8f5; border: 1px solid #eee1d5; border-radius: 10px; padding: 16px; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; }
        .label { color: #7b6f66; font-size: 13px; }
        .value { color: #2c1810; font-weight: 600; font-size: 13px; text-align: right; }
        .footer { margin-top: 18px; font-size: 12px; color: #888; }
    </style>
</head>
<body>
@php
  /** @var \App\Models\Appointment $appointment */
  $appointment = $appointment;
  $service = $appointment->services->first()?->service;
@endphp
    <div class="container">
        <h1>Your booking is confirmed</h1>
        <p>Hi {{ $appointment->customer?->name ?? 'there' }}, your appointment has been booked successfully.</p>

        <div class="card">
            <div class="row">
                <span class="label">Service</span>
                <span class="value">{{ $service?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="label">Salon / Location</span>
                <span class="value">{{ $appointment->branch?->name ?? '—' }}</span>
            </div>
            @if(!empty($appointment->branch?->address))
                <div class="row">
                    <span class="label">Address</span>
                    <span class="value">{{ $appointment->branch->address }}</span>
                </div>
            @endif
            <div class="row">
                <span class="label">Staff</span>
                <span class="value">{{ $appointment->staff?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="label">Date</span>
                <span class="value">{{ optional($appointment->starts_at)->format('D, M j, Y') }}</span>
            </div>
            <div class="row">
                <span class="label">Time</span>
                <span class="value">
                    {{ optional($appointment->starts_at)->format('H:i') }}
                    –
                    {{ optional($appointment->ends_at)->format('H:i') }}
                </span>
            </div>
            <div class="row">
                <span class="label">Status</span>
                <span class="value">{{ $appointment->status }}</span>
            </div>
        </div>

        <div class="footer">
            If you didn’t request this booking, please contact the salon.
        </div>
    </div>
</body>
</html>

