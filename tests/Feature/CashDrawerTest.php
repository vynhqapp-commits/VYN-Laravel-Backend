<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_open_movement_close_and_reconcile_flow(): void
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

        // Open
        $open = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/cash-drawers/open', [
                'branch_id' => $branch->id,
                'opening_balance' => 100,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($open);

        $drawer = CashDrawer::withoutGlobalScopes()->where('branch_id', $branch->id)->first();
        $this->assertNotNull($drawer);

        $session = CashDrawerSession::query()->where('cash_drawer_id', $drawer->id)->where('status', 'open')->first();
        $this->assertNotNull($session);

        // Movement
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/cash-drawers/{$session->id}/transaction", [
                'type' => 'cash_out',
                'amount' => 10,
                'reason' => 'Petty cash',
            ])
            ->assertCreated();

        // Close with actual != expected so we write ledger entry
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/cash-drawers/{$session->id}/close", [
                'actual_cash' => 80,
                'notes' => '',
            ])
            ->assertOk();

        $session->refresh();
        $this->assertEquals('closed', $session->status);

        $ledger = LedgerEntry::withoutGlobalScopes()
            ->where('reference_type', CashDrawerSession::class)
            ->where('reference_id', $session->id)
            ->where('category', 'cash_over_short')
            ->first();

        $this->assertNotNull($ledger);

        // Reconcile
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/cash-drawers/{$session->id}/approve", [])
            ->assertOk();

        $session->refresh();
        $this->assertEquals('reconciled', $session->status);
    }
}

