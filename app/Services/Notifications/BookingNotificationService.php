<?php

namespace App\Services\Notifications;

use App\Models\Appointment;
use App\Notifications\BookingConfirmedPushNotification;

class BookingNotificationService
{
    public function __construct(private readonly SmsNotificationService $smsService)
    {
    }

    public function sendConfirmation(Appointment $appointment): void
    {
        $customer = $appointment->customer;
        if (!$customer) {
            return;
        }

        $serviceName = (string) optional($appointment->services->first()?->service)->name;
        $branchName = (string) optional($appointment->branch)->name;
        $startsAt = optional($appointment->starts_at)->format('Y-m-d H:i');

        if (!empty($customer->phone)) {
            $this->smsService->send(
                (string) $customer->phone,
                sprintf(
                    'Booking confirmed: %s on %s at %s.',
                    $serviceName !== '' ? $serviceName : 'Appointment',
                    $startsAt ?: 'scheduled time',
                    $branchName !== '' ? $branchName : 'your salon'
                )
            );
        }

        $user = $customer->relationLoaded('user') ? $customer->user : $customer->user()->first();
        if ($user) {
            $user->notify(new BookingConfirmedPushNotification($appointment));
        }
    }
}
