<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;

class EnforceStaffBranch
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('api');
        if (!$user || !method_exists($user, 'hasAnyRole')) {
            return $next($request);
        }

        // Only enforce for operational staff roles (single-branch assignment).
        if (!$user->hasAnyRole(['staff', 'receptionist'])) {
            return $next($request);
        }

        $tenantId = (int) ($user->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant required'], 422);
        }

        $staff = Staff::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $branchId = (int) $staff->branch_id;

        // Enforce request body param (POST/PATCH etc).
        if ($request->has('branch_id')) {
            $requested = (int) $request->input('branch_id');
            if ($requested !== $branchId) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        } else {
            // If not provided, inject it so controllers that rely on branch_id filters become safe by default.
            $request->merge(['branch_id' => $branchId]);
        }

        // Enforce query param (GET list endpoints).
        $queryBranch = $request->query('branch_id');
        if ($queryBranch !== null) {
            if ((int) $queryBranch !== $branchId) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        } else {
            $request->query->set('branch_id', (string) $branchId);
        }

        return $next($request);
    }
}

