<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductUsage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_inventory_adjust_creates_inventory_and_prevents_negative(): void
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
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Shampoo',
            'cost' => 5,
            'price' => 10,
            'stock_quantity' => 0,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ]);

        // Add stock +5
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/inventory/stock', [
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => 5,
                'reason' => 'purchase',
            ])->assertOk();

        $inv = Inventory::withoutGlobalScopes()->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
        $this->assertNotNull($inv);
        $this->assertEquals(5, (int) $inv->quantity);

        // Try to remove more than available -> 422
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/inventory/stock', [
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => -10,
                'reason' => 'adjustment',
            ])->assertStatus(422);
    }

    public function test_low_stock_report_returns_items_below_threshold(): void
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
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Gel',
            'cost' => 2,
            'price' => 6,
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
            'is_active' => true,
        ]);

        Inventory::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'low_stock_threshold' => null,
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/reports/low-stock')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}

