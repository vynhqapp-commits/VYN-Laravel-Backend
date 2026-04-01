<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppointmentReminderPushNotification;
use App\Services\Notifications\SmsNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AppointmentRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_due_24h_and_1h_reminders_once(): void
    {
        Queue::fake();

        $appointment24h = $this->createAppointmentAt(Carbon::now()->addHours(24)->addMinutes(2));
        $appointment1h = $this->createAppointmentAt(Carbon::now()->addHour()->addMinutes(2));

        $this->artisan('appointments:send-reminders', ['--window' => 5])->assertExitCode(0);

        Queue::assertPushed(SendAppointmentReminderJob::class, 2);
        Queue::assertPushed(SendAppointmentReminderJob::class, fn (SendAppointmentReminderJob $job) => $job->appointmentId === $appointment24h->id && $job->reminderType === '24h');
        Queue::assertPushed(SendAppointmentReminderJob::class, fn (SendAppointmentReminderJob $job) => $job->appointmentId === $appointment1h->id && $job->reminderType === '1h');

        $this->assertNotNull($appointment24h->fresh()->reminder_24h_sent_at);
        $this->assertNotNull($appointment1h->fresh()->reminder_1h_sent_at);

        Queue::fake();
        $this->artisan('appointments:send-reminders', ['--window' => 5])->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    public function test_delivery_job_sends_email_sms_and_push(): void
    {
        Mail::fake();
        Notification::fake();

        $appointment = $this->createAppointmentAt(Carbon::now()->addHour()->addMinutes(2), withUser: true);

        $sms = Mockery::mock(SmsNotificationService::class);
        $sms->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $to, string $message) => $to === '+201000000001' && str_contains($message, 'Reminder:'));
        $this->app->instance(SmsNotificationService::class, $sms);

        SendAppointmentReminderJob::dispatchSync($appointment->id, '1h');

        Mail::assertSent(\App\Mail\AppointmentReminderMail::class);
        Notification::assertSentTo($appointment->customer->user, AppointmentReminderPushNotification::class);
    }

    private function createAppointmentAt(Carbon $startsAt, bool $withUser = false): Appointment
    {
        $tenant = Tenant::create(['name' => 'Reminder Salon ' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'is_active' => true,
        ]);
        $staff = Staff::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Nora',
            'is_active' => true,
        ]);
        $service = Service::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hair Cut',
            'duration_minutes' => 60,
            'price' => 80,
            'cost' => 0,
            'is_active' => true,
        ]);

        $user = $withUser ? User::factory()->create(['tenant_id' => $tenant->id]) : null;
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user?->id,
            'name' => 'Customer',
            'email' => 'customer' . uniqid() . '@example.com',
            'phone' => '+201000000001',
        ]);

        $appointment = Appointment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => 'scheduled',
            'source' => 'public',
        ]);

        AppointmentService::create([
            'appointment_id' => $appointment->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'duration_minutes' => $service->duration_minutes,
        ]);

        return $appointment;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
