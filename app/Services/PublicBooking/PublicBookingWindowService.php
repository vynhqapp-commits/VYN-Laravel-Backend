<?php

namespace App\Services\PublicBooking;

use App\Models\ServiceBranchAvailability;
use App\Models\ServiceBranchAvailabilityOverride;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PublicBookingWindowService
{
    /**
     * @return Collection<int, array{start_time: string, end_time: string, slot_minutes: mixed}>
     */
    public function resolveServiceWindows(int $tenantId, int $serviceId, int $branchId, Carbon $date): Collection
    {
        $overrideRows = ServiceBranchAvailabilityOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('service_id', $serviceId)
            ->where('branch_id', $branchId)
            ->where('date', $date->toDateString())
            ->orderBy('start_time')
            ->get();

        if ($overrideRows->isNotEmpty()) {
            if ($overrideRows->contains(fn ($r) => (bool) $r->is_closed)) {
                return collect();
            }

            return $overrideRows->map(fn ($r) => [
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'slot_minutes' => $r->slot_minutes,
            ])->filter(fn ($w) => ! empty($w['start_time']) && ! empty($w['end_time']))->values();
        }

        return ServiceBranchAvailability::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('service_id', $serviceId)
            ->where('branch_id', $branchId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(fn ($r) => [
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'slot_minutes' => $r->slot_minutes,
            ]);
    }

    /**
     * @param  Collection<int, array{start_time: string, end_time: string, slot_minutes?: mixed}>  $windows
     */
    public function isWithinServiceWindow(Carbon $startAt, Carbon $endAt, Collection $windows): bool
    {
        foreach ($windows as $window) {
            $windowStart = Carbon::parse($startAt->toDateString() . ' ' . $window['start_time']);
            $windowEnd = Carbon::parse($startAt->toDateString() . ' ' . $window['end_time']);
            if ($startAt->gte($windowStart) && $endAt->lte($windowEnd)) {
                return true;
            }
        }

        return false;
    }
}
