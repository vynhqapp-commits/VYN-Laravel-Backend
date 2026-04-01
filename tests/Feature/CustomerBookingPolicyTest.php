<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerBookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_cancel_inside_24h_applies_with_policy_warning(): void
    {
        [$token, $appt] = $this->makeCustomerAppointment(now()->addHours(2));

        $this->withToken($token)
            ->patchJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'cancelled')
            ->assertJsonPath('data.policy.window_hours', 24)
            ->assertJsonPath('data.policy.violated', true);
    }

    public function test_cancel_outside_24h_applies_without_policy_violation(): void
    {
        [$token, $appt] = $this->makeCustomerAppointment(now()->addHours(30));

        $this->withToken($token)
            ->patchJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'cancelled')
            ->assertJsonPath('data.policy.violated', false);
    }

    public function test_reschedule_inside_24h_applies_with_policy_warning(): void
    {
        [$token, $appt] = $this->makeCustomerAppointment(now()->addHours(2));

        $newStart = now()->addHours(5)->toISOString();

        $this->withToken($token)
            ->patchJson("/api/customer/bookings/{$appt->id}/reschedule", [
                'start_at' => $newStart,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.policy.violated', true);
    }

    public function test_reschedule_conflict_returns_422(): void
    {
        [$token, $appt] = $this->makeCustomerAppointment(now()->addHours(30));

        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $appt->tenant_id,
            'branch_id' => $appt->branch_id,
            'customer_id' => $appt->customer_id,
            'staff_id' => $appt->staff_id,
            'starts_at' => now()->addHours(40),
            'ends_at' => now()->addHours(40)->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $this->withToken($token)
            ->patchJson("/api/customer/bookings/{$appt->id}/reschedule", [
                'start_at' => now()->addHours(40)->toISOString(),
            ])
            ->assertStatus(422);
    }

    public function test_cancel_past_booking_still_blocked(): void
    {
        [$token, $appt] = $this->makeCustomerAppointment(now()->subHour());

        $this->withToken($token)
            ->patchJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertStatus(422);
    }

    private function makeCustomerAppointment(Carbon $startsAt): array
    {
        $tenant = Tenant::create(['name' => 'Policy Salon ' . uniqid()]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'customer');
        $token = auth('api')->login($user);

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Stylist',
            'is_active' => true,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Customer A',
            'email' => $user->email,
        ]);

        $appointment = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => 'scheduled',
        ]);

        return [$token, $appointment];
    }
}
