<?php

namespace App\Jobs;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Notifications\AppointmentReminderPushNotification;
use App\Services\Notifications\SmsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class SendAppointmentReminderJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $appointmentId,
        public string $reminderType
    ) {
    }

    public function handle(SmsNotificationService $smsService): void
    {
        $appointment = Appointment::withoutGlobalScopes()
            ->with(['customer.user', 'branch', 'services.service', 'staff'])
            ->find($this->appointmentId);

        if (!$appointment || !$appointment->customer) {
            return;
        }

        $customer = $appointment->customer;
        $serviceName = (string) optional($appointment->services->first()?->service)->name;
        $startsAt = optional($appointment->starts_at)->format('Y-m-d H:i');
        $branchName = (string) optional($appointment->branch)->name;
        $windowLabel = $this->reminderType === '24h' ? '24 hours' : '1 hour';

        if (!empty($customer->email)) {
            try {
                Mail::to($customer->email)->send(new AppointmentReminderMail($appointment, $this->reminderType));
            } catch (\Throwable $e) {
                Log::warning('appointment.reminder.email_failed', [
                    'appointment_id' => $appointment->id,
                    'reminder_type' => $this->reminderType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($customer->phone)) {
            try {
                $smsService->send(
                    (string) $customer->phone,
                    sprintf(
                        'Reminder: Your %s appointment is in %s (%s at %s).',
                        $serviceName !== '' ? $serviceName : 'salon',
                        $windowLabel,
                        $startsAt ?: 'scheduled time',
                        $branchName !== '' ? $branchName : 'your salon'
                    )
                );
            } catch (\Throwable $e) {
                Log::warning('appointment.reminder.sms_failed', [
                    'appointment_id' => $appointment->id,
                    'reminder_type' => $this->reminderType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $user = $customer->relationLoaded('user') ? $customer->user : $customer->user()->first();
        if ($user) {
            try {
                $user->notify(new AppointmentReminderPushNotification($appointment, $this->reminderType));
            } catch (\Throwable $e) {
                Log::warning('appointment.reminder.push_failed', [
                    'appointment_id' => $appointment->id,
                    'reminder_type' => $this->reminderType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
