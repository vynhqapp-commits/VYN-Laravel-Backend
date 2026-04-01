<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceBranchAvailability;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedPushNotification;
use App\Services\Notifications\SmsNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class BookingConfirmationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_booking_sends_push_and_sms_confirmation(): void
    {
        Notification::fake();
        Mail::fake();

        $tenant = Tenant::create([
            'name' => 'Glow Salon',
            'slug' => 'glow-salon',
        ]);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'is_active' => true,
        ]);

        $service = Service::create([
            'tenant_id' => $tenant->id,
            'name' => 'Blow Dry',
            'duration_minutes' => 60,
            'price' => 120,
            'cost' => 0,
            'is_active' => true,
        ]);

        $staff = Staff::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Sana',
            'is_active' => true,
        ]);

        $startAt = Carbon::now()->addDays(2)->setTime(10, 0, 0);
        $dayOfWeek = $startAt->dayOfWeek;

        StaffSchedule::create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_day_off' => false,
        ]);

        ServiceBranchAvailability::create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Aisha',
            'phone' => '+201000000001',
            'email' => 'aisha@example.com',
        ]);

        $sms = Mockery::mock(SmsNotificationService::class);
        $sms->shouldReceive('send')
            ->once()
            ->withArgs(function (string $to, string $message) use ($customer) {
                return $to === $customer->phone && str_contains($message, 'Booking confirmed');
            });
        $this->app->instance(SmsNotificationService::class, $sms);

        $response = $this->postJson('/api/public/book', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'start_at' => $startAt->toIso8601String(),
            'client_name' => 'Aisha',
            'client_phone' => $customer->phone,
            'client_email' => $customer->email,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Appointment booked successfully');

        Notification::assertSentTo($user, BookingConfirmedPushNotification::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
