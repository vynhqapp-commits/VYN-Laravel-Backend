<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingConfirmedPushNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Appointment $appointment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $serviceName = (string) optional($this->appointment->services->first()?->service)->name;
        $branchName = (string) optional($this->appointment->branch)->name;

        return [
            'type' => 'booking_confirmation',
            'title' => 'Booking Confirmed',
            'message' => sprintf(
                'Your %s booking is confirmed for %s at %s.',
                $serviceName !== '' ? $serviceName : 'appointment',
                optional($this->appointment->starts_at)->format('Y-m-d H:i'),
                $branchName !== '' ? $branchName : 'the salon'
            ),
            'appointment_id' => $this->appointment->id,
            'tenant_id' => $this->appointment->tenant_id,
            'branch_id' => $this->appointment->branch_id,
            'starts_at' => optional($this->appointment->starts_at)->toISOString(),
        ];
    }
}
