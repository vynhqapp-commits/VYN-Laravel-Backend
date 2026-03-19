<?php

namespace App\TenantFinder;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class HeaderTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?Tenant
    {
        $value = $request->header('X-Tenant');

        if (!$value) {
            return null;
        }

        // Backwards/Frontend compatibility:
        // - Some clients send tenant "slug"
        // - Others send tenant numeric/string "id"
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return Tenant::where('id', (int) $value)->first();
        }

        return Tenant::where('slug', $value)->first();
    }
}
