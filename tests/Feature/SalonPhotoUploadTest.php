<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalonPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    public function test_manager_can_upload_salon_photo_for_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Luxe Salon']);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'manager');
        $token = auth('api')->login($user);

        $res = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->post('/api/salons/' . $tenant->id . '/photos', [
                'photo' => UploadedFile::fake()->image('salon.jpg', 800, 600),
                'alt_text' => 'Front entrance',
                'sort_order' => 1,
            ], ['Accept' => 'application/json']);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.photo.alt_text', 'Front entrance')
            ->assertJsonPath('data.photo.sort_order', 1);

        $this->assertDatabaseHas('salon_photos', [
            'salon_id' => $tenant->id,
            'alt_text' => 'Front entrance',
            'sort_order' => 1,
        ]);
    }

    public function test_upload_fails_for_other_tenant_salon(): void
    {
        $tenantA = Tenant::create(['name' => 'Salon A']);
        $tenantB = Tenant::create(['name' => 'Salon B']);

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');
        $token = auth('api')->login($user);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenantA->id)
            ->post('/api/salons/' . $tenantB->id . '/photos', [
                'photo' => UploadedFile::fake()->image('salon.jpg'),
            ], ['Accept' => 'application/json'])->assertStatus(403);
    }
}

