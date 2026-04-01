<?php

namespace App\Services\Reviews;

use App\Models\Tenant;

class ReviewModerationService
{
    public function refreshTenantAverageRating(int|string $tenantId): void
    {
        $average = \App\Models\Review::withoutGlobalScopes()
            ->where('salon_id', $tenantId)
            ->where('status', 'approved')
            ->avg('rating');

        Tenant::withoutGlobalScopes()
            ->whereKey($tenantId)
            ->update([
                'average_rating' => $average !== null ? round((float) $average, 2) : null,
            ]);
    }
}

