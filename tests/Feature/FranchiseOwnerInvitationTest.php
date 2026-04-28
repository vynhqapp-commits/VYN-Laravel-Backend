<?php

namespace Tests\Feature;

use App\Mail\FranchiseOwnerInvitationMail;
use App\Models\Branch;
use App\Models\FranchiseOwnerInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FranchiseOwnerInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_invite_franchise_owner_and_accept_creates_user_and_scoped_analytics(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Salon Franchise']);
        $branchA = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $branchB = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($owner, 'salon_owner');
        $token = auth('api')->login($owner);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/franchise-owner-invitations', [
                'email' => 'franchise@example.com',
                'name' => 'Fran Owner',
                'branch_ids' => [$branchA->id],
            ])
            ->assertCreated();

        Mail::assertSent(FranchiseOwnerInvitationMail::class);

        $inv = FranchiseOwnerInvitation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'franchise@example.com')
            ->latest('id')
            ->firstOrFail();

        $plainToken = 'known-franchise-token';
        $inv->update(['token_hash' => hash('sha256', $plainToken)]);

        $this->postJson('/api/auth/franchise-owner-invitations/accept', [
            'token' => $plainToken,
            'name' => 'Fran Owner',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'franchise@example.com')
            ->firstOrFail();

        $this->assertTrue($user->hasRole('franchise_owner'));

        $this->assertTrue(
            DB::table('franchise_owner_branches')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('branch_id', $branchA->id)
                ->exists()
        );

        // Franchise owner analytics should be scoped to owned branches only.
        $frToken = auth('api')->login($user);
        $locations = $this->withToken($frToken)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('/api/analytics/franchise')
            ->assertOk()
            ->json('data.locations');

        $this->assertCount(1, $locations);
        $this->assertSame((string) $branchA->id, (string) ($locations[0]['id'] ?? ''));
        $this->assertNotSame((string) $branchB->id, (string) ($locations[0]['id'] ?? ''));
    }
}

