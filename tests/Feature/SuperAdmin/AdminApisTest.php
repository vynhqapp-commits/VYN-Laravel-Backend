<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminApisTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_roles_and_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::where('email', 'admin@platform.com')->firstOrFail();

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/permissions')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_non_super_admin_is_forbidden_on_admin_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test-' . uniqid() . '.example',
            'plan' => 'basic',
            'subscription_status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->syncRoles([Role::findByName('salon_owner', 'api')]);

        $this->actingAs($user, 'api')
            ->getJson('/api/admin/tenants')
            ->assertStatus(403);
    }

    public function test_super_admin_users_crud_and_audit_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::where('email', 'admin@platform.com')->firstOrFail();
        $tenant = Tenant::create([
            'name' => 'Tenant For Users',
            'domain' => 'users-' . uniqid() . '.example',
            'plan' => 'basic',
            'subscription_status' => 'active',
        ]);

        $create = $this->actingAs($admin, 'api')->postJson('/api/admin/users', [
            'email' => 'teststaff@example.com',
            'name' => 'Test Staff',
            'role' => 'staff',
            'tenant_id' => $tenant->id,
            'password' => 'password123',
        ]);
        $create->assertStatus(201)->assertJsonPath('success', true);

        $id = (string) ($create->json('data.id') ?? $create->json('data.user.id'));
        $this->assertNotEmpty($id);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/admin/users/' . $id, ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/audit')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_can_manage_direct_user_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::where('email', 'admin@platform.com')->firstOrFail();
        $tenant = Tenant::create([
            'name' => 'Tenant Permission Overrides',
            'domain' => 'permissions-' . uniqid() . '.example',
            'plan' => 'basic',
            'subscription_status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'override-user@example.com',
        ]);
        $user->syncRoles([Role::findByName('staff', 'api')]);

        $this->actingAs($admin, 'api')
            ->putJson('/api/admin/users/' . $user->id . '/permissions', [
                'permissions' => ['reports.export'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', (string) $user->id);

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/users/' . $user->id . '/permissions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['reports.export']);
    }
}

