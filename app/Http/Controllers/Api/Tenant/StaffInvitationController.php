<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvitationMail;
use App\Models\Branch;
use App\Models\Staff;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StaffInvitationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'status' => 'nullable|in:pending,accepted,revoked,expired',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = StaffInvitation::query()->with(['branch:id,name', 'inviter:id,name,email'])->latest('id');
            $status = $data['status'] ?? null;
            if ($status === 'pending') {
                $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
            } elseif ($status === 'accepted') {
                $q->whereNotNull('accepted_at');
            } elseif ($status === 'revoked') {
                $q->whereNotNull('revoked_at');
            } elseif ($status === 'expired') {
                $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now());
            }

            $rows = $q->paginate(50)->through(fn (StaffInvitation $inv) => $this->toArray($inv));
            return $this->paginated($rows, 'Invitations retrieved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'email' => 'required|email|max:255',
                'name' => 'nullable|string|max:255',
                'role' => 'required|in:staff,receptionist',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tenant = Tenant::findOrFail(auth('api')->user()->tenant_id);
            $branch = null;
            if (!empty($data['branch_id'])) {
                $branch = Branch::query()->whereKey($data['branch_id'])->where('tenant_id', $tenant->id)->first();
                if (!$branch) {
                    return $this->error('Invalid branch for this tenant.', 422);
                }
            }

            $activeExists = StaffInvitation::query()
                ->where('email', strtolower($data['email']))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();
            if ($activeExists) {
                return $this->error('An active invitation already exists for this email.', 422);
            }

            $token = Str::random(64);
            $invitation = StaffInvitation::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch?->id,
                'invited_by' => auth('api')->id(),
                'email' => strtolower($data['email']),
                'name' => $data['name'] ?? null,
                'role' => $data['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);

            $this->sendInvitationEmail($invitation, $token, $tenant->name, $branch?->name);

            return $this->created($this->toArray($invitation->load(['branch:id,name', 'inviter:id,name,email'])), 'Invitation sent');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function resend(StaffInvitation $staffInvitation)
    {
        try {
            if ($staffInvitation->accepted_at) {
                return $this->error('Invitation already accepted.', 422);
            }
            if ($staffInvitation->revoked_at) {
                return $this->error('Invitation has been revoked.', 422);
            }

            $token = Str::random(64);
            $staffInvitation->update([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);

            $tenant = Tenant::findOrFail(auth('api')->user()->tenant_id);
            $this->sendInvitationEmail($staffInvitation->load('branch:id,name'), $token, $tenant->name, $staffInvitation->branch?->name);

            return $this->success($this->toArray($staffInvitation->fresh()->load(['branch:id,name', 'inviter:id,name,email'])), 'Invitation resent');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function revoke(StaffInvitation $staffInvitation)
    {
        try {
            if ($staffInvitation->accepted_at) {
                return $this->error('Invitation already accepted.', 422);
            }
            if ($staffInvitation->revoked_at) {
                return $this->success($this->toArray($staffInvitation->load(['branch:id,name', 'inviter:id,name,email'])), 'Invitation already revoked');
            }

            $staffInvitation->update(['revoked_at' => now()]);
            return $this->success($this->toArray($staffInvitation->fresh()->load(['branch:id,name', 'inviter:id,name,email'])), 'Invitation revoked');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function accept(Request $request)
    {
        try {
            $data = $request->validate([
                'token' => 'required|string|min:20',
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tokenHash = hash('sha256', $data['token']);

            $invitation = StaffInvitation::withoutGlobalScopes()
                ->where('token_hash', $tokenHash)
                ->first();
            if (!$invitation) {
                return $this->error('Invalid invitation token.', 422);
            }
            if ($invitation->accepted_at) {
                return $this->error('Invitation already accepted.', 422);
            }
            if ($invitation->revoked_at) {
                return $this->error('Invitation has been revoked.', 422);
            }
            if ($invitation->expires_at && $invitation->expires_at->isPast()) {
                return $this->error('Invitation has expired.', 422);
            }

            DB::transaction(function () use ($invitation, $data) {
                $user = User::withoutGlobalScopes()
                    ->where('email', $invitation->email)
                    ->where('tenant_id', $invitation->tenant_id)
                    ->first();

                if (!$user) {
                    $user = User::withoutGlobalScopes()->create([
                        'tenant_id' => $invitation->tenant_id,
                        'name' => $data['name'],
                        'email' => $invitation->email,
                        'password' => Hash::make($data['password']),
                    ]);
                } else {
                    $user->update([
                        'name' => $data['name'],
                        'password' => Hash::make($data['password']),
                    ]);
                }

                $role = Role::findByName($invitation->role, 'api');
                $user->syncRoles([$role]);

                if ($invitation->role === 'staff') {
                    $staff = Staff::withoutGlobalScopes()
                        ->where('tenant_id', $invitation->tenant_id)
                        ->where('user_id', $user->id)
                        ->first();
                    if (!$staff) {
                        Staff::withoutGlobalScopes()->create([
                            'tenant_id' => $invitation->tenant_id,
                            'branch_id' => $invitation->branch_id,
                            'user_id' => $user->id,
                            'name' => $data['name'],
                            'is_active' => true,
                        ]);
                    } elseif (!$staff->branch_id && $invitation->branch_id) {
                        $staff->update(['branch_id' => $invitation->branch_id]);
                    }
                }

                $invitation->update(['accepted_at' => now()]);
            });

            return $this->success(null, 'Invitation accepted successfully. You can now log in.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function sendInvitationEmail(StaffInvitation $invitation, string $token, string $tenantName, ?string $branchName): void
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $inviteUrl = $frontendUrl.'/invite/staff?token='.$token;

        Mail::to($invitation->email)->send(new StaffInvitationMail(
            salonName: $tenantName,
            inviteUrl: $inviteUrl,
            role: $invitation->role,
            branchName: $branchName,
            inviteeName: $invitation->name,
            expiresAt: $invitation->expires_at,
        ));
    }

    private function toArray(StaffInvitation $inv): array
    {
        $status = 'pending';
        if ($inv->accepted_at) {
            $status = 'accepted';
        } elseif ($inv->revoked_at) {
            $status = 'revoked';
        } elseif ($inv->expires_at && $inv->expires_at->isPast()) {
            $status = 'expired';
        }

        return [
            'id' => (string) $inv->id,
            'email' => $inv->email,
            'name' => $inv->name,
            'role' => $inv->role,
            'branch_id' => $inv->branch_id ? (string) $inv->branch_id : null,
            'branch_name' => $inv->branch?->name,
            'status' => $status,
            'expires_at' => optional($inv->expires_at)->toISOString(),
            'accepted_at' => optional($inv->accepted_at)->toISOString(),
            'revoked_at' => optional($inv->revoked_at)->toISOString(),
            'invited_by' => $inv->inviter ? [
                'id' => (string) $inv->inviter->id,
                'name' => $inv->inviter->name,
                'email' => $inv->inviter->email,
            ] : null,
            'created_at' => optional($inv->created_at)->toISOString(),
        ];
    }
}
