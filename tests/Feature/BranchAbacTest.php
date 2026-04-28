<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchAbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_staff_cannot_query_other_branch_and_is_auto_scoped_to_own_branch(): void
    {
        $tenant = Tenant::create(['name' => 'Salon ABAC']);
        $branchA = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $branchB = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $staffUser = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($staffUser, 'staff');

        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'user_id' => $staffUser->id,
            'name' => 'Staff A',
            'is_active' => true,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane',
            'phone' => '123',
        ]);

        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'scheduled',
            'source' => 'dashboard',
        ]);

        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
            'source' => 'dashboard',
        ]);

        // Explicit cross-branch query is forbidden.
        $this->actingAs($staffUser, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/appointments?branch_id=' . $branchB->id)
            ->assertForbidden();

        // No branch_id specified: auto-scoped to staff's branch.
        $data = $this->actingAs($staffUser, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/appointments')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame((int) $branchA->id, (int) ($data[0]['branch_id'] ?? 0));
    }
}

