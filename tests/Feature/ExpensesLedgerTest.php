<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExpensesLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_expense_update_updates_ledger_entry(): void
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

        $create = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/expenses', [
                'branch_id' => $branch->id,
                'category' => 'rent',
                'amount' => 100,
                'expense_date' => now()->toDateString(),
                'description' => 'Rent',
            ])
            ->assertStatus(201)
            ->json('data');

        $expenseId = (string) ($create['id'] ?? '');
        $this->assertNotEmpty($expenseId);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/expenses/{$expenseId}", [
                'amount' => 200,
                'description' => 'Rent updated',
            ])
            ->assertOk();

        $expense = Expense::withoutGlobalScopes()->findOrFail($expenseId);
        $this->assertEquals('200.00', (string) $expense->amount);

        $ledger = LedgerEntry::withoutGlobalScopes()
            ->where('reference_type', Expense::class)
            ->where('reference_id', $expense->id)
            ->where('type', 'expense')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($ledger);
        $this->assertEquals('200.00', (string) $ledger->amount);
        $this->assertEquals('Rent updated', (string) $ledger->description);
    }

    public function test_expense_delete_creates_reversal_ledger_entry_and_soft_deletes_expense(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'manager');
        $token = auth('api')->login($user);

        $create = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/expenses', [
                'category' => 'utilities',
                'amount' => 50,
                'expense_date' => now()->toDateString(),
                'description' => 'Electricity',
            ])
            ->assertStatus(201)
            ->json('data');

        $expenseId = (string) ($create['id'] ?? '');
        $this->assertNotEmpty($expenseId);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->deleteJson("/api/expenses/{$expenseId}")
            ->assertOk();

        $this->assertSoftDeleted('expenses', ['id' => (int) $expenseId]);

        $reversal = LedgerEntry::withoutGlobalScopes()
            ->where('reference_type', Expense::class)
            ->where('reference_id', (int) $expenseId)
            ->where('type', 'expense')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($reversal);
        $this->assertEquals('-50.00', (string) $reversal->amount);
    }
}

