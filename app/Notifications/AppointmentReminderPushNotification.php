<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppointmentReminderPushNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Appointment $appointment,
        private readonly string $reminderType
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $serviceName = (string) optional($this->appointment->services->first()?->service)->name;
        $branchName = (string) optional($this->appointment->branch)->name;
        $windowLabel = $this->reminderType === '24h' ? '24 hours' : '1 hour';

        return [
            'type' => 'appointment_reminder',
            'title' => 'Appointment Reminder',
            'message' => sprintf(
                'Reminder: %s starts in %s at %s.',
                $serviceName !== '' ? $serviceName : 'Your appointment',
                $windowLabel,
                $branchName !== '' ? $branchName : 'the salon'
            ),
            'appointment_id' => $this->appointment->id,
            'tenant_id' => $this->appointment->tenant_id,
            'branch_id' => $this->appointment->branch_id,
            'starts_at' => optional($this->appointment->starts_at)->toISOString(),
            'reminder_type' => $this->reminderType,
        ];
    }
}
