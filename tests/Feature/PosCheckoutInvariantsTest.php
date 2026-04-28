<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosCheckoutInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_sale_rejects_overpayment(): void
    {
        $tenant = Tenant::create(['name' => 'Salon POS']);
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

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/cash-drawers/open', [
                'branch_id' => $branch->id,
                'opening_balance' => 0,
            ])
            ->assertCreated();

        $this->withToken($token)
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
                    ['method' => 'cash', 'amount' => 120],
                ],
            ])
            ->assertStatus(422);
    }
}

