<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NearbySalonsTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug('-') . '-' . uniqid(),
            'subscription_status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ]);
    }

    private function createBranch(Tenant $tenant, float $lat, float $lng): Branch
    {
        return Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch',
            'timezone' => 'UTC',
            'is_active' => true,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    public function test_nearby_salons_returns_sorted_by_distance_and_includes_distance_km(): void
    {
        // Beirut-ish as an anchor point
        $anchorLat = 33.8938;
        $anchorLng = 35.5018;

        $near = $this->createTenant('Near');
        $far = $this->createTenant('Far');

        $this->createBranch($near, 33.8940, 35.5020);
        $this->createBranch($far, 34.0000, 35.6500);

        $res = $this->getJson('/api/public/salons/nearby?lat=' . $anchorLat . '&lng=' . $anchorLng . '&radius_km=200')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // Ensure distance_km present and sorted
        $this->assertArrayHasKey('distance_km', $data[0]);
        $this->assertSame((string) $near->id, (string) $data[0]['id']);
    }

    public function test_nearby_salons_validates_params(): void
    {
        $this->getJson('/api/public/salons/nearby')->assertStatus(422);
        $this->getJson('/api/public/salons/nearby?lat=200&lng=0')->assertStatus(422);
        $this->getJson('/api/public/salons/nearby?lat=0&lng=200')->assertStatus(422);
        $this->getJson('/api/public/salons/nearby?lat=0&lng=0&radius_km=0')->assertStatus(422);
    }
}

