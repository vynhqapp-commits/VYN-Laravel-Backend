<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SuperAdmin\IndexAdminUsersRequest;
use App\Http\Requests\Api\SuperAdmin\StoreAdminUserRequest;
use App\Http\Requests\Api\SuperAdmin\UpdateAdminUserRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(IndexAdminUsersRequest $request)
    {
        $data = $request->validated();

        try {
            $q = User::query()->with(['roles'])->latest();

            if (!empty($data['tenant_id'])) $q->where('tenant_id', $data['tenant_id']);
            if (!empty($data['role'])) $q->whereHas('roles', fn ($qq) => $qq->where('name', $data['role']));
            if (!empty($data['q'])) {
                $needle = trim((string) $data['q']);
                $q->where(function ($qq) use ($needle) {
                    $qq->where('email', 'like', '%' . $needle . '%')
                        ->orWhere('name', 'like', '%' . $needle . '%');
                });
            }

            $users = $q->paginate(50);

            $rows = collect($users->items())->map(function (User $u) {
                $role = $u->roles->pluck('name')->first() ?? null;
                return [
                    'id' => (string) $u->id,
                    'email' => $u->email,
                    'name' => $u->name,
                    'tenant_id' => $u->tenant_id ? (string) $u->tenant_id : null,
                    'role' => $role,
                    'created_at' => optional($u->created_at)->toISOString(),
                ];
            })->values();
            $users->setCollection($rows);

            return $this->paginated($users, 'Users retrieved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(StoreAdminUserRequest $request)
    {
        $data = $request->validated();

        try {
            $user = User::create([
                'email' => $data['email'],
                'name' => $data['name'] ?? $data['email'],
                'password' => Hash::make($data['password'] ?? str()->random(12)),
                'tenant_id' => $data['tenant_id'] ?? null,
            ]);

            $user->syncRoles([$data['role']]);
            AuditLogger::log(optional($request->user('api'))->id, $user->tenant_id ? (int) $user->tenant_id : null, 'admin.user.create', [
                'user_id' => (string) $user->id,
                'role' => $data['role'],
            ]);

            return $this->created([
                'id' => (string) $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'tenant_id' => $user->tenant_id ? (string) $user->tenant_id : null,
                'role' => $data['role'],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(UpdateAdminUserRequest $request, User $user)
    {
        $data = $request->validated();

        try {
            if (array_key_exists('name', $data)) $user->name = $data['name'] ?? $user->name;
            if (array_key_exists('tenant_id', $data)) $user->tenant_id = $data['tenant_id'];
            if (!empty($data['password'])) $user->password = Hash::make($data['password']);
            $user->save();

            if (!empty($data['role'])) $user->syncRoles([$data['role']]);
            AuditLogger::log(optional($request->user('api'))->id, $user->tenant_id ? (int) $user->tenant_id : null, 'admin.user.update', [
                'user_id' => (string) $user->id,
                'fields' => array_keys($data),
            ]);

            $role = $user->roles()->pluck('name')->first();
            return $this->success([
                'id' => (string) $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'tenant_id' => $user->tenant_id ? (string) $user->tenant_id : null,
                'role' => $role,
            ], 'User updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(User $user)
    {
        try {
            AuditLogger::log(optional(request()->user('api'))->id, $user->tenant_id ? (int) $user->tenant_id : null, 'admin.user.delete', [
                'user_id' => (string) $user->id,
                'email' => $user->email,
            ]);
            $user->delete();
            return $this->success(null, 'User deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

