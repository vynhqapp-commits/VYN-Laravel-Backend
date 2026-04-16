<?php

namespace Tests\Feature;

use App\Mail\StaffInvitationMail;
use App\Models\Branch;
use App\Models\Staff;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffInvitationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_manager_can_send_staff_invitation_email(): void
    {
        Mail::fake();
        [$token, $tenant, $branch] = $this->managerContext();

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/staff-invitations', [
                'email' => 'invitee@example.com',
                'name' => 'Invited Staff',
                'role' => 'staff',
                'branch_id' => $branch->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'invitee@example.com')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.status', 'pending');

        Mail::assertSent(StaffInvitationMail::class);
    }

    public function test_receptionist_cannot_send_invitation(): void
    {
        $tenant = Tenant::create(['name' => 'Salon Reception']);
        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($receptionist, 'receptionist');
        $token = auth('api')->login($receptionist);

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/staff-invitations', [
                'email' => 'x@example.com',
                'role' => 'staff',
            ])
            ->assertForbidden();
    }

    public function test_invitation_accept_creates_user_and_staff_profile(): void
    {
        [$token, $tenant, $branch] = $this->managerContext();
        $inviteEmail = 'accepted.staff@example.com';

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/staff-invitations', [
                'email' => $inviteEmail,
                'name' => 'Accepted Staff',
                'role' => 'staff',
                'branch_id' => $branch->id,
            ])
            ->assertCreated();

        $inv = StaffInvitation::withoutGlobalScopes()->where('email', $inviteEmail)->latest('id')->firstOrFail();

        // Simulate magic-link token known by email recipient.
        $plainToken = 'known-token-for-test';
        $inv->update(['token_hash' => hash('sha256', $plainToken)]);

        $this->postJson('/api/auth/staff-invitations/accept', [
            'token' => $plainToken,
            'name' => 'Accepted Staff',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $inviteEmail)
            ->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('staff'));

        $staff = Staff::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame((int) $branch->id, (int) $staff->branch_id);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        [$token, $tenant] = $this->managerContext();

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/staff-invitations', [
                'email' => 'expired@example.com',
                'name' => 'Expired User',
                'role' => 'receptionist',
            ])
            ->assertCreated();

        $inv = StaffInvitation::withoutGlobalScopes()->where('email', 'expired@example.com')->latest('id')->firstOrFail();
        $tokenPlain = 'expired-token-test';
        $inv->update([
            'token_hash' => hash('sha256', $tokenPlain),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/auth/staff-invitations/accept', [
            'token' => $tokenPlain,
            'name' => 'Expired User',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422);
    }

    public function test_cannot_accept_same_invitation_twice(): void
    {
        [$token, $tenant] = $this->managerContext();

        $this->withToken($token)
            ->withHeader('X-Tenant', (string) $tenant->id)
            ->postJson('/api/staff-invitations', [
                'email' => 'once@example.com',
                'name' => 'Only Once',
                'role' => 'receptionist',
            ])
            ->assertCreated();

        $inv = StaffInvitation::withoutGlobalScopes()->where('email', 'once@example.com')->latest('id')->firstOrFail();
        $tokenPlain = 'single-use-token-0123456789';
        $inv->update(['token_hash' => hash('sha256', $tokenPlain)]);

        $payload = [
            'token' => $tokenPlain,
            'name' => 'Only Once',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->postJson('/api/auth/staff-invitations/accept', $payload)->assertOk();
        $this->postJson('/api/auth/staff-invitations/accept', $payload)->assertStatus(422);
    }

    private function managerContext(): array
    {
        $tenant = Tenant::create(['name' => 'Salon Invitation']);
        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignRole($manager, 'manager');
        $token = auth('api')->login($manager);

        return [$token, $tenant, $branch];
    }
}
