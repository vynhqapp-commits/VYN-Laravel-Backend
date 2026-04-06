<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GiftCardRoutePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_staff_cannot_create_gift_card(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password'  => Hash::make('secret123'),
        ]);
        $this->assignRole($staff, 'staff');
        $token = auth('api')->login($staff);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/gift-cards', ['initial_balance' => 50])
            ->assertForbidden();
    }

    public function test_manager_can_create_gift_card(): void
    {
        $tenant = Tenant::create(['name' => 'Salon B']);
        $manager = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password'  => Hash::make('secret123'),
        ]);
        $this->assignRole($manager, 'manager');
        $token = auth('api')->login($manager);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/gift-cards', ['initial_balance' => 75])
            ->assertCreated()
            ->assertJsonPath('data.initial_balance', 75);
    }

    public function test_staff_cannot_verify_gift_card(): void
    {
        $tenant = Tenant::create(['name' => 'Salon C']);
        GiftCard::withoutGlobalScopes()->create([
            'tenant_id'         => $tenant->id,
            'code'              => 'GC-STAFF-BLOCK',
            'initial_balance'   => 20,
            'remaining_balance' => 20,
            'currency'          => 'USD',
            'status'            => 'active',
        ]);

        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password'  => Hash::make('secret123'),
        ]);
        $this->assignRole($staff, 'staff');
        $token = auth('api')->login($staff);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/gift-cards/verify', ['code' => 'GC-STAFF-BLOCK'])
            ->assertForbidden();
    }

    public function test_receptionist_can_verify_gift_card(): void
    {
        $tenant = Tenant::create(['name' => 'Salon D']);
        GiftCard::withoutGlobalScopes()->create([
            'tenant_id'         => $tenant->id,
            'code'              => 'GC-RECEP-OK',
            'initial_balance'   => 30,
            'remaining_balance' => 30,
            'currency'          => 'USD',
            'status'            => 'active',
        ]);

        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password'  => Hash::make('secret123'),
        ]);
        $this->assignRole($receptionist, 'receptionist');
        $token = auth('api')->login($receptionist);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/gift-cards/verify', ['code' => 'GC-RECEP-OK'])
            ->assertOk()
            ->assertJsonPath('data.code', 'GC-RECEP-OK');
    }

    public function test_receptionist_cannot_issue_gift_card(): void
    {
        $tenant = Tenant::create(['name' => 'Salon E']);
        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password'  => Hash::make('secret123'),
        ]);
        $this->assignRole($receptionist, 'receptionist');
        $token = auth('api')->login($receptionist);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/gift-cards', ['initial_balance' => 10])
            ->assertForbidden();
    }
}
