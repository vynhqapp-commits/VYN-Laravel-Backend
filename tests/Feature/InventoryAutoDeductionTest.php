<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductUsage;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryAutoDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_completing_appointment_deducts_inventory_using_service_recipe(): void
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
        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Stylist',
            'is_active' => true,
        ]);
        $service = Service::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Coloring',
            'duration_minutes' => 60,
            'price' => 100,
            'cost' => 30,
            'is_active' => true,
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Dye',
            'cost' => 5,
            'price' => 10,
            'stock_quantity' => 0,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ]);

        ServiceProductUsage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        Inventory::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $appt = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now(),
            'status' => 'checked_in',
        ]);

        AppointmentService::create([
            'appointment_id' => $appt->id,
            'service_id' => $service->id,
            'price' => 100,
            'duration_minutes' => 60,
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/appointments/{$appt->id}", ['status' => 'completed'])
            ->assertOk();

        $inv = Inventory::withoutGlobalScopes()->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
        $this->assertNotNull($inv);
        $this->assertEquals(4, (int) $inv->quantity);
    }
}

