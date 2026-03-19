<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductsAndSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_products_index_requires_tenant_header(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');
        $token = auth('api')->login($user);

        $this->withToken($token)
            ->getJson('/api/products')
            ->assertStatus(400);
    }

    public function test_products_crud_is_scoped_to_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Salon A']);
        $tenantB = Tenant::create(['name' => 'Salon B']);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($userA, 'salon_owner');
        $tokenA = auth('api')->login($userA);

        // Create product in tenant A
        $create = $this->withToken($tokenA)
            ->withHeader('X-Tenant', (string) $tenantA->id)
            ->postJson('/api/products', [
                'name' => 'Shampoo',
                'sku' => 'SKU-1',
                'cost' => 5,
                'price' => 10,
                'stock_quantity' => 3,
            ])
            ->assertStatus(201)
            ->json('data');

        $productId = (string) ($create['id'] ?? '');
        $this->assertNotEmpty($productId);

        // Attempt to read with another tenant header should 403 (user belongs to tenantA but header says tenantB)
        $this->withToken($tokenA)
            ->withHeader('X-Tenant', (string) $tenantB->id)
            ->getJson("/api/products/{$productId}")
            ->assertStatus(403);
    }

    public function test_sale_partial_payment_creates_debt_and_ledger_entries(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $tenant->vat_rate = 11.00;
        $tenant->save();
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

        $res = $this->withToken($token)
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
                'tips_amount' => 10,
                'payments' => [
                    ['method' => 'cash', 'amount' => 40],
                ],
            ])
            ->assertStatus(201)
            ->json('data');

        $invoiceId = (string) ($res['id'] ?? '');
        $this->assertNotEmpty($invoiceId);

        $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoiceId);
        $this->assertEquals('partial', $invoice->status);

        // Debt created for remaining amount
        $this->assertDatabaseHas('debts', [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'status' => 'open',
        ]);

        // Ledger entries written (revenue)
        $this->assertTrue(
            LedgerEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('reference_type', Invoice::class)
                ->where('reference_id', $invoice->id)
                ->exists()
        );

        // VAT tax_amount should be populated on revenue rows when vat_rate is set
        $this->assertTrue(
            LedgerEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('reference_type', Invoice::class)
                ->where('reference_id', $invoice->id)
                ->where('type', 'revenue')
                ->where('tax_amount', '>', 0)
                ->exists()
        );
    }
}

