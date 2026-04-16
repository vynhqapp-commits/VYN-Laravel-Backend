<?php

namespace App\Http\Middleware;

use App\TenantFinder\HeaderTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Models\Tenant;

class EnsureTenant
{
    public function handle(Request $request, Closure $next)
    {
        // Resolve tenant from X-Tenant (id or slug) using the same finder configured in config/multitenancy.php.
        $finder = app(HeaderTenantFinder::class);
        $tenant = $finder->findForRequest($request);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant header (X-Tenant) is required',
                'errors' => ['X-Tenant' => ['Missing or invalid tenant identifier.']],
            ], 400);
        }

        // Make tenant current so BelongsToTenant global scopes apply everywhere.
        // Base tenant model provides makeCurrent() in Spatie multitenancy.
        $tenant->makeCurrent();

        // Optionally, enforce tenant membership for non-super-admin users.
        $user = $request->user('api');
        if ($user) {
            $isSuperAdmin = method_exists($user, 'hasRole') && $user->hasRole('super_admin');
            if (!$isSuperAdmin) {
                // Users are single-tenant in this baseline: user.tenant_id must match current tenant.
                if ((string) ($user->tenant_id ?? '') !== (string) $tenant->getKey()) {
                    // Ensure we don't leak whether a tenant exists to non-authorized users.
                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden',
                    ], 403);
                }
            }
        }

        $response = $next($request);

        // Avoid leaking tenant context across requests/jobs in long-running processes.
        if (class_exists(Tenant::class) && method_exists(Tenant::class, 'forgetCurrent')) {
            Tenant::forgetCurrent();
        }

        return $response;
    }
}

