<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles — required for assignRole() calls in AuthController
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // LOGIN
    // -------------------------------------------------------------------------

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $this->assignRole($user, 'customer');

        $res = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'tenantId', 'role']]]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    public function test_login_fails_with_missing_fields(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'test@example.com'])
            ->assertStatus(422);
    }

    public function test_login_returns_correct_role(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pass')]);
        $this->assignRole($user, 'salon_owner');

        $res = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'pass',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.user.role', 'salon_owner');
    }

    // -------------------------------------------------------------------------
    // REGISTER CUSTOMER
    // -------------------------------------------------------------------------

    public function test_register_customer_creates_user_with_customer_role(): void
    {
        $res = $this->postJson('/api/auth/register/customer', [
            'email'     => 'jane@example.com',
            'password'  => 'password123',
            'full_name' => 'Jane Doe',
            'phone'     => '+1234567890',
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'tenantId', 'role']]]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertNull($user->tenant_id);
    }

    public function test_register_customer_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/auth/register/customer', [
            'email'    => 'dup@example.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_register_customer_fails_with_short_password(): void
    {
        $this->postJson('/api/auth/register/customer', [
            'email'    => 'new@example.com',
            'password' => '123',
        ])->assertStatus(422);
    }

    public function test_register_customer_uses_email_as_name_when_full_name_omitted(): void
    {
        $this->postJson('/api/auth/register/customer', [
            'email'    => 'noname@example.com',
            'password' => 'password123',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'noname@example.com',
            'name'  => 'noname@example.com',
        ]);
    }

    // -------------------------------------------------------------------------
    // REGISTER SALON OWNER
    // -------------------------------------------------------------------------

    public function test_register_salon_owner_creates_tenant_and_user(): void
    {
        $res = $this->postJson('/api/auth/register/salon-owner', [
            'salon_name' => 'Luxe Salon',
            'email'      => 'owner@luxe.com',
            'password'   => 'password123',
            'full_name'  => 'Luxe Owner',
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'tenantId', 'role']]]);

        $this->assertDatabaseHas('tenants', ['name' => 'Luxe Salon']);
        $this->assertDatabaseHas('users', ['email' => 'owner@luxe.com']);

        $user = User::where('email', 'owner@luxe.com')->first();
        $this->assertTrue($user->hasRole('salon_owner'));
        $this->assertNotNull($user->tenant_id);

        // tenantId in response matches the created tenant
        $tenantId = $res->json('data.user.tenantId');
        $this->assertEquals($user->tenant_id, $tenantId);
    }

    public function test_register_salon_owner_fails_without_salon_name(): void
    {
        $this->postJson('/api/auth/register/salon-owner', [
            'email'    => 'owner@test.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_register_salon_owner_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register/salon-owner', [
            'salon_name' => 'Another Salon',
            'email'      => 'taken@example.com',
            'password'   => 'password123',
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // OTP — SEND
    // -------------------------------------------------------------------------

    public function test_otp_send_accepts_simple_email_payload(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/request-otp', ['email' => 'user@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(OtpMail::class, 1);

        $this->assertDatabaseHas('otp_codes', [
            'identifier' => 'user@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'is_used'    => false,
        ]);
    }

    public function test_otp_send_replaces_existing_unused_otp(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/request-otp', ['email' => 'user@example.com']);
        $this->postJson('/api/auth/request-otp', ['email' => 'user@example.com']);

        Mail::assertQueued(OtpMail::class, 2);
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_otp_send_fails_without_email(): void
    {
        $this->postJson('/api/auth/request-otp', [])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // OTP — VERIFY
    // -------------------------------------------------------------------------

    public function test_otp_verify_logs_in_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'otp@example.com']);
        $this->assignRole($user, 'customer');

        OtpCode::create([
            'identifier' => 'otp@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'code'       => '123456',
            'expires_at' => now()->addMinutes(10),
        ]);

        $res = $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code'  => '123456',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('otp_codes', [
            'identifier' => 'otp@example.com',
            'is_used'    => true,
        ]);
    }

    public function test_otp_verify_returns_verified_true_for_unknown_email(): void
    {
        OtpCode::create([
            'identifier' => 'ghost@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'code'       => '999999',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'ghost@example.com',
            'code'  => '999999',
        ])->assertOk()
          ->assertJsonPath('data.verified', true);
    }

    public function test_otp_verify_fails_with_wrong_code(): void
    {
        OtpCode::create([
            'identifier' => 'user@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'code'       => '111111',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'user@example.com',
            'code'  => '000000',
        ])->assertStatus(422);
    }

    public function test_otp_verify_fails_with_expired_otp(): void
    {
        OtpCode::create([
            'identifier' => 'user@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'code'       => '111111',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'user@example.com',
            'code'  => '111111',
        ])->assertStatus(422);
    }

    public function test_otp_verify_fails_with_already_used_otp(): void
    {
        OtpCode::create([
            'identifier' => 'user@example.com',
            'type'       => 'email',
            'purpose'    => 'login',
            'code'       => '111111',
            'expires_at' => now()->addMinutes(10),
            'is_used'    => true,
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'user@example.com',
            'code'  => '111111',
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // ME + LOGOUT
    // -------------------------------------------------------------------------

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'customer');
        $token = auth('api')->login($user);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'customer');
    }

    public function test_me_fails_without_token(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'customer');
        $token = auth('api')->login($user);

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        // Token should no longer work
        $this->withToken($token)
            ->getJson('/api/me')
            ->assertStatus(401);
    }
}
