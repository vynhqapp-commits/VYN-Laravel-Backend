<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\FranchiseOwnerInvitationMail;
use App\Models\Branch;
use App\Models\FranchiseOwnerInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class FranchiseOwnerInvitationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'email' => 'required|email',
                'name' => 'nullable|string|max:255',
                'branch_ids' => 'required|array|min:1',
                'branch_ids.*' => 'integer|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            $branchIds = array_values(array_unique(array_map('intval', $data['branch_ids'])));
            $owned = Branch::query()->whereIn('id', $branchIds)->count();
            if ($owned !== count($branchIds)) {
                return $this->validationError(['branch_ids' => ['Invalid branches for tenant.']]);
            }

            $activeExists = FranchiseOwnerInvitation::query()
                ->where('email', strtolower($data['email']))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();
            if ($activeExists) {
                return $this->error('An active invitation already exists for this email.', 422);
            }

            $token = Str::random(64);
            $inv = FranchiseOwnerInvitation::create([
                'tenant_id' => (int) $tenantId,
                'invited_by' => (int) auth('api')->id(),
                'email' => strtolower($data['email']),
                'name' => $data['name'] ?? null,
                'branch_ids' => $branchIds,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);

            $this->sendInviteEmail($inv, $token);

            return $this->created($this->toDto($inv), 'Invitation sent');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Accept franchise owner invitation.
     * @unauthenticated
     */
    public function accept(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'token' => 'required|string',
                'name' => 'nullable|string|max:255',
                'password' => 'required|string|min:8|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tokenHash = hash('sha256', (string) $data['token']);

            $inv = FranchiseOwnerInvitation::withoutGlobalScopes()
                ->where('token_hash', $tokenHash)
                ->first();

            if (!$inv) return $this->error('Invalid invitation token.', 422);
            if ($inv->accepted_at) return $this->error('Invitation already accepted.', 422);
            if ($inv->revoked_at) return $this->error('Invitation has been revoked.', 422);
            if ($inv->expires_at && $inv->expires_at->isPast()) return $this->error('Invitation has expired.', 422);

            DB::transaction(function () use ($inv, $data) {
                $user = User::withoutGlobalScopes()->firstOrCreate(
                    ['email' => $inv->email],
                    [
                        'tenant_id' => $inv->tenant_id,
                        'name' => $data['name'] ?? $inv->name ?? $inv->email,
                        'password' => Hash::make((string) $data['password']),
                    ]
                );

                if ((int) $user->tenant_id !== (int) $inv->tenant_id) {
                    throw new \RuntimeException('User belongs to a different tenant.');
                }

                $user->assignRole(Role::findByName('franchise_owner', 'api'));

                $branchIds = array_values(array_unique(array_map('intval', (array) ($inv->branch_ids ?? []))));
                foreach ($branchIds as $branchId) {
                    DB::table('franchise_owner_branches')->updateOrInsert([
                        'tenant_id' => (int) $inv->tenant_id,
                        'user_id' => (int) $user->id,
                        'branch_id' => (int) $branchId,
                    ], [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $inv->update(['accepted_at' => now()]);
            });

            return $this->success(null, 'Invitation accepted successfully. You can now log in.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private function sendInviteEmail(FranchiseOwnerInvitation $inv, string $token): void
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $inviteUrl = rtrim($frontendUrl, '/').'/invite/franchise-owner?token='.$token;

        $salonName = $inv->tenant?->name ?? 'VYN';

        Mail::to($inv->email)->send(new FranchiseOwnerInvitationMail(
            salonName: $salonName,
            inviteUrl: $inviteUrl,
            inviteeName: $inv->name,
            expiresAt: $inv->expires_at,
        ));
    }

    private function toDto(FranchiseOwnerInvitation $inv): array
    {
        $status = 'pending';
        if ($inv->accepted_at) $status = 'accepted';
        elseif ($inv->revoked_at) $status = 'revoked';
        elseif ($inv->expires_at && $inv->expires_at->isPast()) $status = 'expired';

        return [
            'id' => (int) $inv->id,
            'email' => $inv->email,
            'name' => $inv->name,
            'branch_ids' => $inv->branch_ids ?? [],
            'status' => $status,
            'expires_at' => optional($inv->expires_at)->toISOString(),
            'accepted_at' => optional($inv->accepted_at)->toISOString(),
            'revoked_at' => optional($inv->revoked_at)->toISOString(),
        ];
    }
}

