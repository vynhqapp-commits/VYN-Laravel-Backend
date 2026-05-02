<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Locks in integer ID typing across all API responses.
 *
 * Pre-fix bug (Problem #1 in 08-DOCS/problems-identified.md):
 *   13 of 23 Resource classes cast IDs to string via `(string) $this->id`.
 *   `GET /api/expenses` returned `"id": "3"` (JSON string) while
 *   `GET /api/customers` returned `"id": 10` (JSON number) — same backend,
 *   different wire types. Frontend had to defensively `Number(id)` everywhere.
 *
 * Pre-fix bug (Problem #2):
 *   `POST /api/login` returned `"tenantId": 1` (camelCase) while every
 *   other API field is snake_case.
 *
 * Post-fix:
 *   Every `id` and every `*_id` foreign key is a JSON number.
 *   `tenant_id` is the canonical name everywhere, including in the user
 *   payload returned by login.
 */
class IdTypeConsistencyTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Problem #2 — login response uses snake_case tenant_id, integer ids
    // -------------------------------------------------------------------------

    public function test_login_response_uses_snake_case_tenant_id(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Login Test']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'login-test@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');

        $response = $this->postJson('/api/login', [
            'email' => 'login-test@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $userPayload = $response->json('data.user');

        $this->assertArrayHasKey('tenant_id', $userPayload, 'login response must use snake_case tenant_id');
        $this->assertArrayNotHasKey('tenantId', $userPayload, 'login response must NOT use camelCase tenantId');
        $this->assertIsInt($userPayload['tenant_id'], 'tenant_id must be a JSON number');
        $this->assertIsInt($userPayload['id'], 'user.id must be a JSON number');
    }

    // -------------------------------------------------------------------------
    // Problem #1 — every Resource returns integer ids and integer FKs
    // -------------------------------------------------------------------------

    public function test_expense_resource_returns_integer_ids(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Expense']);
        $token = $this->tenantOwnerToken($tenant);
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        Expense::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'category' => 'rent',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
            'description' => 'Test expense',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/expenses')
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertNotNull($first, 'expense list must contain at least one row');
        $this->assertIsInt($first['id'], 'expense.id must be integer (was string before fix)');
        $this->assertIsInt($first['tenant_id'], 'expense.tenant_id must be integer');
        $this->assertIsInt($first['branch_id'], 'expense.branch_id must be integer');
    }

    public function test_coupon_resource_returns_integer_ids(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Coupon']);
        $token = $this->tenantOwnerToken($tenant);
        Coupon::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAVE10',
            'name' => '10% off',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/coupons')
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertNotNull($first, 'coupon list must contain at least one row');
        $this->assertIsInt($first['id'], 'coupon.id must be integer (was string before fix)');
    }

    public function test_product_resource_returns_integer_ids(): void
    {
        // GET /api/products/{id} (show) uses ProductResource — that's the
        // path that was returning string ids. Index returns raw model and
        // already returned int, so that's not the canary.
        $tenant = Tenant::create(['name' => 'Salon Product']);
        $token = $this->tenantOwnerToken($tenant);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Shampoo',
            'sku' => 'SHA-001',
            'price' => 25.50,
            'cost' => 10.00,
            'is_active' => true,
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson("/api/products/{$product->id}")
            ->assertOk();

        $row = $response->json('data');
        $this->assertNotNull($row, 'product show response must have data');
        $this->assertIsInt($row['id'], 'product.id must be integer (was string before fix)');
        $this->assertIsInt($row['tenant_id'], 'product.tenant_id must be integer (was string before fix)');
    }

    public function test_branch_resource_returns_integer_ids(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Branch']);
        $token = $this->tenantOwnerToken($tenant);
        Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Downtown',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/branches')
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertNotNull($first, 'branch list must contain at least one row');
        $this->assertIsInt($first['id'], 'branch.id must be integer');
    }

    public function test_customer_resource_returns_integer_ids(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Customer']);
        $token = $this->tenantOwnerToken($tenant);
        Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'phone' => '+1000',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/customers')
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertNotNull($first, 'customer list must contain at least one row');
        $this->assertIsInt($first['id'], 'customer.id must be integer');
    }
}
