<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerFavoritesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_customer_can_add_list_and_remove_favorites(): void
    {
        [$token, $customer] = $this->makeCustomerContext();
        $salon = Tenant::create(['name' => 'Fav Salon A']);

        $this->withToken($token)
            ->postJson('/api/customer/favorites', ['salon_id' => $salon->id])
            ->assertStatus(200)
            ->assertJsonPath('data.favorite.salon_id', $salon->id);

        $this->assertDatabaseHas('customer_favorites', [
            'customer_id' => $customer->id,
            'salon_id' => $salon->id,
        ]);

        $this->withToken($token)
            ->getJson('/api/customer/favorites')
            ->assertStatus(200)
            ->assertJsonPath('data.favorites.0.salon_id', $salon->id);

        $this->withToken($token)
            ->deleteJson("/api/customer/favorites/{$salon->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('customer_favorites', [
            'customer_id' => $customer->id,
            'salon_id' => $salon->id,
        ]);
    }

    public function test_duplicate_favorite_is_idempotent(): void
    {
        [$token, $customer] = $this->makeCustomerContext();
        $salon = Tenant::create(['name' => 'Fav Salon B']);

        $this->withToken($token)->postJson('/api/customer/favorites', ['salon_id' => $salon->id])->assertStatus(200);
        $this->withToken($token)->postJson('/api/customer/favorites', ['salon_id' => $salon->id])->assertStatus(200);

        $this->assertSame(1, \App\Models\CustomerFavorite::query()
            ->where('customer_id', $customer->id)
            ->where('salon_id', $salon->id)
            ->count());
    }

    private function makeCustomerContext(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant X']);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'customer');
        $token = auth('api')->login($user);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Customer X',
            'email' => $user->email,
        ]);

        return [$token, $customer];
    }
}
