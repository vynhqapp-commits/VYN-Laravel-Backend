<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function roles()
    {
        try {
            $roles = Role::query()->with('permissions')->orderBy('name')->get();
            $rows = $roles->map(function (Role $r) {
                return [
                    'name' => $r->name,
                    'description' => $r->name === 'super_admin'
                        ? 'Full platform access, all tenants'
                        : ($r->name === 'salon_owner'
                            ? 'Full access within their own salon'
                            : ($r->name === 'manager'
                                ? 'Daily operations and POS in assigned branches'
                                : ($r->name === 'staff'
                                    ? 'Schedule & operational access'
                                    : 'Booking & history'))),
                    'scopes' => $r->permissions->pluck('name')->values(),
                ];
            })->values();

            return $this->success($rows);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function permissions()
    {
        try {
            $perms = Permission::query()->orderBy('name')->pluck('name')->values();
            return $this->success($perms);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

