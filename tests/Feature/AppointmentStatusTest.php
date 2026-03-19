<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppointmentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_invalid_status_transition_returns_422(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');
        $token = auth('api')->login($user);

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane',
        ]);
        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Stylist',
            'is_active' => true,
        ]);
        $service = Service::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 50,
            'cost' => 10,
            'is_active' => true,
        ]);

        $appt = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDay()->addMinutes(30),
            'status' => 'cancelled',
        ]);

        // Cannot transition cancelled -> completed
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/appointments/{$appt->id}", ['status' => 'completed'])
            ->assertStatus(422);
    }
}

