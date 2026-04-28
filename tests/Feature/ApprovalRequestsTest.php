<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApprovalRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_receptionist_appointment_delete_creates_approval_request_and_manager_can_approve(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Approval']);

        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($manager, 'manager');

        $receptionist = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($receptionist, 'receptionist');

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane',
            'phone' => '123',
        ]);

        Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $receptionist->id,
            'name' => 'Receptionist',
            'is_active' => true,
        ]);
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($staffUser, 'staff');
        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $staffUser->id,
            'name' => 'Staff A',
            'is_active' => true,
        ]);

        $appointment = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'scheduled',
            'source' => 'dashboard',
        ]);

        $res = $this->actingAs($receptionist, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->deleteJson('/api/appointments/' . $appointment->id)
            ->assertStatus(202)
            ->json('data');

        $approvalId = (int) ($res['id'] ?? 0);
        $this->assertGreaterThan(0, $approvalId);

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'tenant_id' => $tenant->id,
            'entity_type' => 'appointment',
            'entity_id' => $appointment->id,
            'requested_action' => 'delete',
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->actingAs($manager, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/approval-requests/' . $approvalId . '/approve', [])
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalRequest::STATUS_APPROVED);

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    public function test_expire_command_marks_stale_requests_expired(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Expiry']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($user, 'manager');

        $req = ApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'entity_type' => 'appointment',
            'entity_id' => 1,
            'requested_action' => 'delete',
            'requested_by' => $user->id,
            'payload' => null,
            'status' => ApprovalRequest::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('approval-requests:expire')->assertExitCode(0);

        $this->assertDatabaseHas('approval_requests', [
            'id' => $req->id,
            'status' => ApprovalRequest::STATUS_EXPIRED,
        ]);
    }

    public function test_receptionist_refund_creates_approval_request_and_manager_can_approve(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Refund Approval']);
        $tenant->vat_rate = 11.00;
        $tenant->save();

        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($manager, 'manager');

        $receptionist = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make('secret123')]);
        $this->assignRole($receptionist, 'receptionist');

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $receptionist->id,
            'name' => 'Receptionist',
            'is_active' => true,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane',
            'phone' => '123',
        ]);

        $service = Service::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 100,
            'cost' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($manager, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/cash-drawers/open', [
                'branch_id' => $branch->id,
                'opening_balance' => 0,
            ])
            ->assertCreated();

        $saleRes = $this->actingAs($manager, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/sales', [
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'items' => [
                    [
                        'service_id' => $service->id,
                        'quantity' => 1,
                        'unit_price' => 100,
                    ],
                ],
                'payments' => [
                    ['method' => 'cash', 'amount' => 100],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $saleId = (int) ($saleRes['id'] ?? 0);
        $this->assertGreaterThan(0, $saleId);

        $this->actingAs($receptionist, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/sales/' . $saleId . '/refund', ['refund_reason' => 'Receptionist requested refund'])
            ->assertStatus(202)
            ->assertJsonPath('data.entity_type', 'sale')
            ->assertJsonPath('data.requested_action', 'refund');

        $approval = ApprovalRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', 'sale')
            ->where('entity_id', $saleId)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($manager, 'api')
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/approval-requests/' . $approval->id . '/approve', ['notes' => 'Approved'])
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalRequest::STATUS_APPROVED);

        $sale = Invoice::withoutGlobalScopes()->findOrFail($saleId);
        $this->assertSame('refunded', (string) $sale->status);
    }
}

