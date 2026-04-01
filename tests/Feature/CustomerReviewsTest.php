<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Review;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_customer_can_submit_review_for_completed_booking(): void
    {
        [$token, $appointment] = $this->makeCompletedCustomerAppointment();

        $this->withToken($token)
            ->postJson("/api/customer/bookings/{$appointment->id}/review", [
                'rating' => 5,
                'comment' => 'Great service',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.review.status', 'pending');

        $this->assertDatabaseHas('reviews', [
            'appointment_id' => $appointment->id,
            'status' => 'pending',
            'rating' => 5,
        ]);
    }

    public function test_customer_cannot_submit_duplicate_review_for_same_appointment(): void
    {
        [$token, $appointment] = $this->makeCompletedCustomerAppointment();

        $this->withToken($token)->postJson("/api/customer/bookings/{$appointment->id}/review", [
            'rating' => 4,
        ])->assertStatus(201);

        $this->withToken($token)->postJson("/api/customer/bookings/{$appointment->id}/review", [
            'rating' => 3,
        ])->assertStatus(422);
    }

    public function test_customer_cannot_submit_review_for_non_completed_booking(): void
    {
        [$token, $appointment] = $this->makeCompletedCustomerAppointment('scheduled');

        $this->withToken($token)
            ->postJson("/api/customer/bookings/{$appointment->id}/review", [
                'rating' => 5,
            ])
            ->assertStatus(422);
    }

    public function test_owner_can_approve_review_and_public_profile_exposes_it(): void
    {
        [$customerToken, $appointment, $tenant] = $this->makeCompletedCustomerAppointment();
        $reviewRes = $this->withToken($customerToken)->postJson("/api/customer/bookings/{$appointment->id}/review", [
            'rating' => 5,
            'comment' => 'Excellent',
        ]);
        $reviewId = (int) $reviewRes->json('data.review.id');

        [$ownerToken] = $this->makeOwnerContext($tenant);

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/reviews/{$reviewId}/moderate", ['action' => 'approve'])
            ->assertStatus(200)
            ->assertJsonPath('data.review.status', 'approved');

        $this->assertDatabaseHas('reviews', [
            'id' => $reviewId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'average_rating' => 5.00,
        ]);

        $this->getJson("/api/public/salons/{$tenant->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.salon.reviews.0.rating', 5);
    }

    public function test_rejecting_approved_review_updates_average_rating(): void
    {
        [$customerToken, $appointment, $tenant] = $this->makeCompletedCustomerAppointment();
        $reviewRes = $this->withToken($customerToken)->postJson("/api/customer/bookings/{$appointment->id}/review", [
            'rating' => 4,
        ]);
        $reviewId = (int) $reviewRes->json('data.review.id');
        [$ownerToken] = $this->makeOwnerContext($tenant);

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/reviews/{$reviewId}/moderate", ['action' => 'approve'])
            ->assertStatus(200);

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->patchJson("/api/reviews/{$reviewId}/moderate", ['action' => 'reject'])
            ->assertStatus(200);

        $tenant->refresh();
        $this->assertNull($tenant->average_rating);

        $this->getJson("/api/public/salons/{$tenant->slug}")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.salon.reviews');
    }

    private function makeCompletedCustomerAppointment(string $status = 'completed'): array
    {
        $tenant = Tenant::create(['name' => 'Review Salon ' . uniqid()]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($user, 'customer');
        $token = auth('api')->login($user);

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $staff = Staff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Stylist',
            'is_active' => true,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Customer A',
            'email' => $user->email,
        ]);

        $appointment = Appointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
            'status' => $status,
        ]);

        return [$token, $appointment, $tenant];
    }

    private function makeOwnerContext(Tenant $tenant): array
    {
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($owner, 'salon_owner');
        $token = auth('api')->login($owner);

        return [$token, $owner];
    }
}

