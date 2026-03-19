<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtLedgerEntry;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DebtLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_add_payment_appends_ledger_and_updates_debt(): void
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
        $invoice = Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-1',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'status' => 'partial',
        ]);

        $debt = Debt::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'original_amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 50,
            'status' => 'open',
        ]);

        DebtLedgerEntry::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'debt_id' => $debt->id,
            'invoice_id' => $invoice->id,
            'type' => 'charge',
            'amount' => 50,
            'balance_after' => 50,
            'created_by' => $user->id,
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/debts/{$debt->id}/payment", ['amount' => 10])
            ->assertCreated();

        $debt->refresh();
        $this->assertEquals(40.0, (float) $debt->remaining_amount);

        $last = DebtLedgerEntry::withoutGlobalScopes()->where('debt_id', $debt->id)->latest()->first();
        $this->assertNotNull($last);
        $this->assertEquals('payment', $last->type);
    }

    public function test_write_off_sets_remaining_zero_and_writes_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'manager');
        $token = auth('api')->login($user);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane',
        ]);
        $debt = Debt::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'invoice_id' => Invoice::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => Branch::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Main',
                    'timezone' => 'UTC',
                    'is_active' => true,
                ])->id,
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-2',
                'subtotal' => 100,
                'discount' => 0,
                'tax' => 0,
                'total' => 100,
                'paid_amount' => 0,
                'status' => 'partial',
            ])->id,
            'original_amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 25,
            'status' => 'open',
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson("/api/debts/{$debt->id}/write-off", [])
            ->assertCreated();

        $debt->refresh();
        $this->assertEquals(0.0, (float) $debt->remaining_amount);
    }
}

