<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceBranchAvailability;
use App\Models\ServiceBranchAvailabilityOverride;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalonListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(array $overrides = []): Tenant
    {
        $name = $overrides['name'] ?? ('Salon ' . uniqid());
        $slug = $overrides['slug'] ?? str($name)->slug('-') . '-' . uniqid();

        return Tenant::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'subscription_status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ], $overrides));
    }

    private function createBranch(Tenant $tenant, array $overrides = []): Branch
    {
        return Branch::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'timezone' => 'UTC',
            'is_active' => true,
        ], $overrides));
    }

    private function createService(Tenant $tenant, array $overrides = []): Service
    {
        return Service::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 50,
            'cost' => 0,
            'is_active' => true,
        ], $overrides));
    }

    public function test_salons_returns_all_without_filters(): void
    {
        $t1 = $this->createTenant(['name' => 'Alpha Salon']);
        $t2 = $this->createTenant(['name' => 'Beta Salon']);

        $this->getJson('/api/public/salons')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_filter_by_price_range_min(): void
    {
        $cheap = $this->createTenant(['name' => 'Cheap']);
        $expensive = $this->createTenant(['name' => 'Expensive']);

        $this->createService($cheap, ['price' => 10]);
        $this->createService($expensive, ['price' => 100]);

        $res = $this->getJson('/api/public/salons?price_min=50')->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($expensive->id, $ids);
        $this->assertNotContains($cheap->id, $ids);
    }

    public function test_filter_by_price_range_max(): void
    {
        $cheap = $this->createTenant(['name' => 'Cheap']);
        $expensive = $this->createTenant(['name' => 'Expensive']);

        $this->createService($cheap, ['price' => 10]);
        $this->createService($expensive, ['price' => 100]);

        $res = $this->getJson('/api/public/salons?price_max=50')->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($cheap->id, $ids);
        $this->assertNotContains($expensive->id, $ids);
    }

    public function test_filter_by_price_range_min_and_max(): void
    {
        $low = $this->createTenant(['name' => 'Low']);
        $mid = $this->createTenant(['name' => 'Mid']);
        $high = $this->createTenant(['name' => 'High']);

        $this->createService($low, ['price' => 10]);
        $this->createService($mid, ['price' => 50]);
        $this->createService($high, ['price' => 120]);

        $res = $this->getJson('/api/public/salons?price_min=40&price_max=60')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($mid->id, $ids);
        $this->assertNotContains($low->id, $ids);
        $this->assertNotContains($high->id, $ids);
    }

    public function test_filter_by_rating_min(): void
    {
        $low = $this->createTenant(['name' => 'Low', 'average_rating' => 3.2]);
        $high = $this->createTenant(['name' => 'High', 'average_rating' => 4.6]);
        $null = $this->createTenant(['name' => 'Null', 'average_rating' => null]);

        $res = $this->getJson('/api/public/salons?rating_min=4')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($high->id, $ids);
        $this->assertNotContains($low->id, $ids);
        $this->assertNotContains($null->id, $ids);
    }

    public function test_filter_by_availability_includes_weekly_windows(): void
    {
        $date = now()->addDays(2)->format('Y-m-d');
        $dayOfWeek = now()->addDays(2)->dayOfWeek;

        $with = $this->createTenant(['name' => 'With']);
        $without = $this->createTenant(['name' => 'Without']);

        $withBranch = $this->createBranch($with);
        $withService = $this->createService($with);

        ServiceBranchAvailability::withoutGlobalScopes()->create([
            'tenant_id' => $with->id,
            'service_id' => $withService->id,
            'branch_id' => $withBranch->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        // Ensure the other salon has at least one branch (active) but no availability rows.
        $this->createBranch($without);

        $res = $this->getJson('/api/public/salons?availability=' . $date)->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($with->id, $ids);
        $this->assertNotContains($without->id, $ids);
    }

    public function test_filter_by_availability_excludes_when_overrides_all_closed(): void
    {
        $date = now()->addDays(3)->format('Y-m-d');
        $dayOfWeek = now()->addDays(3)->dayOfWeek;

        $tenant = $this->createTenant(['name' => 'Closed']);
        $branch = $this->createBranch($tenant);
        $service = $this->createService($tenant);

        ServiceBranchAvailability::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        ServiceBranchAvailabilityOverride::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'date' => $date,
            'start_time' => null,
            'end_time' => null,
            'slot_minutes' => null,
            'is_closed' => true,
        ]);

        $res = $this->getJson('/api/public/salons?availability=' . $date)->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertNotContains($tenant->id, $ids);
    }

    public function test_filter_by_gender_preference_matches_tenant_or_branch(): void
    {
        $tenantLevel = $this->createTenant(['name' => 'TenantLevel', 'gender_preference' => 'ladies']);
        $branchLevel = $this->createTenant(['name' => 'BranchLevel', 'gender_preference' => null]);
        $other = $this->createTenant(['name' => 'Other', 'gender_preference' => 'gents']);

        $this->createBranch($tenantLevel);

        $this->createBranch($branchLevel, ['gender_preference' => 'ladies']);
        $this->createBranch($other);

        $res = $this->getJson('/api/public/salons?gender_preference=ladies')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($tenantLevel->id, $ids);
        $this->assertContains($branchLevel->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_combined_filters(): void
    {
        $date = now()->addDays(4)->format('Y-m-d');
        $dayOfWeek = now()->addDays(4)->dayOfWeek;

        $match = $this->createTenant(['name' => 'Match', 'average_rating' => 4.5, 'gender_preference' => 'unisex']);
        $noRating = $this->createTenant(['name' => 'NoRating', 'average_rating' => 3.0, 'gender_preference' => 'unisex']);

        $matchBranch = $this->createBranch($match);
        $matchService = $this->createService($match, ['price' => 55]);

        ServiceBranchAvailability::withoutGlobalScopes()->create([
            'tenant_id' => $match->id,
            'service_id' => $matchService->id,
            'branch_id' => $matchBranch->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        $this->createService($noRating, ['price' => 55]);
        $this->createBranch($noRating);

        $res = $this->getJson('/api/public/salons?price_min=50&price_max=60&rating_min=4&availability=' . $date . '&gender_preference=unisex')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($noRating->id, $ids);
    }

    public function test_validation_rejects_invalid_params(): void
    {
        $this->getJson('/api/public/salons?price_min=-1')->assertStatus(422);
        $this->getJson('/api/public/salons?price_min=10&price_max=5')->assertStatus(422);
        $this->getJson('/api/public/salons?rating_min=6')->assertStatus(422);
        $this->getJson('/api/public/salons?availability=2026-99-99')->assertStatus(422);
        $this->getJson('/api/public/salons?gender_preference=kids')->assertStatus(422);
    }
}

