<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AnalyticsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function authOwnerForTenant(Tenant $tenant): string
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'salon_owner');
        return auth('api')->login($user);
    }

    private function makeBaseData(Tenant $tenant): array
    {
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $customerA = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice',
        ]);
        $customerB = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Bob',
        ]);

        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Stylist',
            'is_active' => true,
        ]);

        $svc1 = Service::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 50,
            'cost' => 10,
            'is_active' => true,
        ]);
        $svc2 = Service::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Blowdry',
            'duration_minutes' => 30,
            'price' => 40,
            'cost' => 8,
            'is_active' => true,
        ]);

        return [$branch, $customerA, $customerB, $staff, $svc1, $svc2];
    }

    public function test_service_popularity_returns_ranked_services(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $token = $this->authOwnerForTenant($tenant);
        [$branch, $customerA, $customerB, $staff, $svc1, $svc2] = $this->makeBaseData($tenant);

        // 2026-04 period
        $a1 = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerA->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 10:30:00'),
            'status' => 'completed',
        ]);
        AppointmentService::create([
            'appointment_id' => $a1->id,
            'service_id' => $svc1->id,
            'price' => 50,
            'duration_minutes' => 30,
        ]);

        $a2 = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-11 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-11 10:30:00'),
            'status' => 'confirmed',
        ]);
        AppointmentService::create([
            'appointment_id' => $a2->id,
            'service_id' => $svc1->id,
            'price' => 50,
            'duration_minutes' => 30,
        ]);
        AppointmentService::create([
            'appointment_id' => $a2->id,
            'service_id' => $svc2->id,
            'price' => 40,
            'duration_minutes' => 30,
        ]);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/reports/service-popularity?period=2026-04&branch_id=' . $branch->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', '2026-04')
            ->assertJsonStructure([
                'data' => [
                    'top_services' => [
                        ['service_id', 'service_name', 'appointment_count'],
                    ],
                ],
            ]);

        $payload = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/reports/service-popularity?period=2026-04&branch_id=' . $branch->id)
            ->json('data.top_services');

        $this->assertNotEmpty($payload);
        $this->assertSame((string) $svc1->id, (string) $payload[0]['service_id']);
        $this->assertSame(2, (int) $payload[0]['appointment_count']);
    }

    public function test_client_retention_counts_repeat_completed_visits_within_period(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $token = $this->authOwnerForTenant($tenant);
        [$branch, $customerA, $customerB, $staff] = $this->makeBaseData($tenant);

        // Customer A: 2 completed in April => retained
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerA->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-05 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-05 10:30:00'),
            'status' => 'completed',
        ]);
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerA->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-20 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-20 10:30:00'),
            'status' => 'completed',
        ]);

        // Customer B: 1 completed in April => not retained
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 11:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 11:30:00'),
            'status' => 'completed',
        ]);

        $res = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/reports/client-retention?period=2026-04&branch_id=' . $branch->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', '2026-04')
            ->assertJsonPath('data.cohort_size', 2)
            ->assertJsonPath('data.retained_customers', 1);

        $rate = (float) $res->json('data.retention_rate');
        $this->assertGreaterThan(0, $rate);
    }

    public function test_no_show_trends_excludes_cancelled_from_denominator(): void
    {
        $tenant = Tenant::create(['name' => 'Salon A']);
        $token = $this->authOwnerForTenant($tenant);
        [$branch, $customerA, $customerB, $staff] = $this->makeBaseData($tenant);

        // April 2026: 1 no_show, 1 completed, 1 cancelled.
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerA->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-08 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-08 10:30:00'),
            'status' => 'no_show',
        ]);
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-09 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-09 10:30:00'),
            'status' => 'completed',
        ]);
        Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'staff_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 10:30:00'),
            'status' => 'cancelled',
        ]);

        $json = $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/reports/no-show-trends?period=2026-04&branch_id=' . $branch->id . '&bucket=week')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', '2026-04')
            ->assertJsonPath('data.bucket', 'week')
            ->json('data.buckets');

        $this->assertIsArray($json);
        $this->assertNotEmpty($json);

        $sumTotal = array_sum(array_map(fn ($r) => (int) ($r['total'] ?? 0), $json));
        $sumNoShow = array_sum(array_map(fn ($r) => (int) ($r['no_show'] ?? 0), $json));

        // Denominator should exclude cancelled => total should be 2, no_show should be 1.
        $this->assertSame(2, $sumTotal);
        $this->assertSame(1, $sumNoShow);
    }
}

