<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_tenant_routes_require_x_tenant_header(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');

        $token = auth('api')->login($user);

        $this->withToken($token)->getJson('/api/branches')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_user_cannot_access_other_tenant_when_header_points_to_other_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Salon A']);
        $tenantB = Tenant::create(['name' => 'Salon B']);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($userA, 'salon_owner');
        $token = auth('api')->login($userA);

        // Even if tenant exists, access must be forbidden.
        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenantB->id)
            ->getJson('/api/branches')
            ->assertStatus(403);
    }

    public function test_list_endpoints_only_return_current_tenant_rows(): void
    {
        $tenantA = Tenant::create(['name' => 'Salon A']);
        $tenantB = Tenant::create(['name' => 'Salon B']);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($userA, 'salon_owner');
        $token = auth('api')->login($userA);

        // Create branches for both tenants (bypass global scopes if any).
        Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'A1',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'B1',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $res = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenantA->id)
            ->getJson('/api/branches')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($res);
        $names = array_map(fn ($b) => $b['name'] ?? null, $res);
        $this->assertContains('A1', $names);
        $this->assertNotContains('B1', $names);
    }
}

