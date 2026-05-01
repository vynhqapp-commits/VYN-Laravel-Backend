<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Locks in role-based access on /api/invoices and /api/ledger.
 *
 * Pre-fix bug: these routes inherited the parent group's wide role list
 * which included `customer`, letting an authenticated customer who set the
 * X-Tenant header read every invoice and the full ledger for that salon —
 * other customers' invoices, staff sales, and revenue records included.
 *
 * Post-fix: invoices read is restricted to back-office roles, void is
 * tightened to owner/manager (matches gift-cards/{card}/void), and ledger
 * is restricted to owner/manager (matches monthly-closings).
 */
class InvoiceLedgerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function tenantOwnerToken(Tenant $tenant): string
    {
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($owner, 'salon_owner');

        return auth('api')->login($owner);
    }

    private function tenantUserTokenWithRole(Tenant $tenant, string $role): string
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, $role);

        // staff/receptionist roles need a Staff record with a branch_id
        // (EnforceStaffBranch middleware enforces this).
        if (in_array($role, ['staff', 'receptionist'], true)) {
            $branch = Branch::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Default Branch',
                'timezone' => 'UTC',
                'is_active' => true,
            ]);
            Staff::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'name' => $user->name ?? 'Staff',
                'is_active' => true,
            ]);
        }

        return auth('api')->login($user);
    }

    private function invoiceForTenant(Tenant $tenant): Invoice
    {
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch '.$tenant->id,
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        return Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-'.$tenant->id.'-001',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'status' => 'open',
        ]);
    }

    // -------------------------------------------------------------------------
    // CUSTOMER role must NOT reach back-office invoice/ledger surfaces
    // -------------------------------------------------------------------------

    public function test_customer_cannot_list_invoices(): void
    {
        $tenant = Tenant::create(['name' => 'Salon C']);
        $token = $this->tenantUserTokenWithRole($tenant, 'customer');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/invoices')
            ->assertStatus(403);
    }

    public function test_customer_cannot_show_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Salon C']);
        $invoice = $this->invoiceForTenant($tenant);
        $token = $this->tenantUserTokenWithRole($tenant, 'customer');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson("/api/invoices/{$invoice->id}")
            ->assertStatus(403);
    }

    public function test_customer_cannot_void_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Salon C']);
        $invoice = $this->invoiceForTenant($tenant);
        $token = $this->tenantUserTokenWithRole($tenant, 'customer');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/invoices/{$invoice->id}/void")
            ->assertStatus(403);
    }

    public function test_customer_cannot_read_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon C']);
        $token = $this->tenantUserTokenWithRole($tenant, 'customer');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/ledger')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Back-office roles MUST keep their existing access (regression guard)
    // -------------------------------------------------------------------------

    public function test_salon_owner_can_list_invoices(): void
    {
        $tenant = Tenant::create(['name' => 'Salon O']);
        $token = $this->tenantOwnerToken($tenant);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/invoices')
            ->assertOk();
    }

    public function test_manager_can_list_invoices(): void
    {
        $tenant = Tenant::create(['name' => 'Salon M']);
        $token = $this->tenantUserTokenWithRole($tenant, 'manager');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/invoices')
            ->assertOk();
    }

    public function test_receptionist_can_list_invoices(): void
    {
        $tenant = Tenant::create(['name' => 'Salon R']);
        $token = $this->tenantUserTokenWithRole($tenant, 'receptionist');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/invoices')
            ->assertOk();
    }

    public function test_staff_can_list_invoices(): void
    {
        $tenant = Tenant::create(['name' => 'Salon S']);
        $token = $this->tenantUserTokenWithRole($tenant, 'staff');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/invoices')
            ->assertOk();
    }

    public function test_salon_owner_can_read_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon LO']);
        $token = $this->tenantOwnerToken($tenant);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/ledger')
            ->assertOk();
    }

    public function test_manager_can_read_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon LM']);
        $token = $this->tenantUserTokenWithRole($tenant, 'manager');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/ledger')
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Ledger is financial overview — receptionist/staff should NOT have it
    // (matches monthly-closings convention at routes/api.php:397)
    // -------------------------------------------------------------------------

    public function test_receptionist_cannot_read_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon RL']);
        $token = $this->tenantUserTokenWithRole($tenant, 'receptionist');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/ledger')
            ->assertStatus(403);
    }

    public function test_staff_cannot_read_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon SL']);
        $token = $this->tenantUserTokenWithRole($tenant, 'staff');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/ledger')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Void is destructive — receptionist/staff should NOT have it
    // (matches gift-cards/{card}/void convention at routes/api.php:438)
    // -------------------------------------------------------------------------

    public function test_receptionist_cannot_void_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Salon RV']);
        $invoice = $this->invoiceForTenant($tenant);
        $token = $this->tenantUserTokenWithRole($tenant, 'receptionist');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/invoices/{$invoice->id}/void")
            ->assertStatus(403);
    }

    public function test_manager_can_void_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Salon MV']);
        $invoice = $this->invoiceForTenant($tenant);
        $token = $this->tenantUserTokenWithRole($tenant, 'manager');

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/invoices/{$invoice->id}/void")
            ->assertOk();
    }
}
