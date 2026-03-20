<?php

namespace App\Services;

use App\Models\MonthlyClosing;
use Carbon\Carbon;

class LedgerService
{
    /**
     * Throw an exception if the month containing $entryDate has been closed for this tenant.
     *
     * Call this before creating any LedgerEntry to enforce the monthly-closing soft lock.
     *
     * @throws \DomainException
     */
    public static function assertNotLocked(int $tenantId, string $entryDate): void
    {
        $date  = Carbon::parse($entryDate);
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $closed = MonthlyClosing::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'closed')
            ->exists();

        if ($closed) {
            throw new \DomainException(
                sprintf(
                    'The period %04d-%02d is closed. No new entries can be posted to a locked period.',
                    $year,
                    $month
                )
            );
        }
    }
}
