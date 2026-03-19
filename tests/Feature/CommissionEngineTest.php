<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_paid_sale_generates_commission_for_appointment_staff(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'manager');
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
            'name' => 'Cut',
            'duration_minutes' => 30,
            'price' => 100,
            'cost' => 20,
            'is_active' => true,
        ]);

        CommissionRule::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'service_id' => null,
            'type' => 'percentage',
            'value' => 10,
            'tier_threshold' => null,
            'is_active' => true,
        ]);

        $appt = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
            'status' => 'scheduled',
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/sales', [
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'appointment_id' => $appt->id,
                'items' => [
                    ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100],
                ],
                'payments' => [
                    ['method' => 'cash', 'amount' => 100],
                ],
            ])->assertCreated();

        $this->assertTrue(CommissionEntry::withoutGlobalScopes()->exists());
    }
}

