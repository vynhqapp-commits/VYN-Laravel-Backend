<?php

namespace App\Services\PublicBooking;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\TimeBlock;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PublicBookingAvailabilityService
{
    public function __construct(
        private readonly PublicBookingWindowService $windows,
    ) {}

    /**
     * @param  array{branch_id: int|string, service_id: int|string, date: string}  $data
     * @return list<array{start: string, end: string, staff_id: mixed}>
     */
    public function computeFreeSlots(array $data): array
    {
        $date = Carbon::parse($data['date']);
        $dayOfWeek = $date->dayOfWeek;
        $service = Service::withoutGlobalScopes()->findOrFail($data['service_id']);
        $duration = (int) $service->duration_minutes;

        $branchWindows = $this->windows->resolveServiceWindows(
            (int) $service->tenant_id,
            (int) $service->id,
            (int) $data['branch_id'],
            $date
        );

        if ($branchWindows->isEmpty()) {
            return [];
        }

        $staffSchedules = StaffSchedule::withoutGlobalScopes()
            ->whereHas('staff', fn ($q) => $q->withoutGlobalScopes()
                ->where('tenant_id', $service->tenant_id)
                ->where('branch_id', $data['branch_id'])
                ->where('is_active', true))
            ->where('day_of_week', $dayOfWeek)
            ->where('is_day_off', false)
            ->get(['staff_id', 'start_time', 'end_time'])
            ->keyBy('staff_id');

        if ($staffSchedules->isEmpty()) {
            return [];
        }

        $booked = Appointment::withoutGlobalScopes()
            ->where('tenant_id', $service->tenant_id)
            ->where('branch_id', $data['branch_id'])
            ->whereDate('starts_at', $date->toDateString())
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->get(['staff_id', 'starts_at', 'ends_at']);

        $blocks = TimeBlock::withoutGlobalScopes()
            ->where('tenant_id', $service->tenant_id)
            ->where('branch_id', $data['branch_id'])
            ->whereDate('starts_at', $date->toDateString())
            ->get(['staff_id', 'starts_at', 'ends_at']);

        $freeSlots = collect();

        foreach ($staffSchedules as $staffId => $sched) {
            foreach ($branchWindows as $window) {
                $svcStart = Carbon::parse($date->toDateString() . ' ' . $window['start_time']);
                $svcEnd = Carbon::parse($date->toDateString() . ' ' . $window['end_time']);

                $shiftStart = Carbon::parse($date->toDateString() . ' ' . $sched->start_time);
                $shiftEnd = Carbon::parse($date->toDateString() . ' ' . $sched->end_time);

                $start = $svcStart->max($shiftStart);
                $end = $svcEnd->min($shiftEnd);

                if ($start->gte($end)) {
                    continue;
                }

                $stepMinutes = (int) ($window['slot_minutes'] ?? 30);
                $staffBooked = $booked->where('staff_id', $staffId);
                $staffBlocks = $blocks->filter(fn ($b) => $b->staff_id === null || (int) $b->staff_id === (int) $staffId);
                $cursor = $start->copy();

                while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                    $slotEnd = $cursor->copy()->addMinutes($duration);
                    $conflict = $staffBooked->first(function ($appt) use ($cursor, $slotEnd) {
                        $aStart = Carbon::parse($appt->starts_at);
                        $aEnd = Carbon::parse($appt->ends_at);

                        return $cursor->lt($aEnd) && $slotEnd->gt($aStart);
                    });

                    $blocked = $staffBlocks->first(function ($blk) use ($cursor, $slotEnd) {
                        $bStart = Carbon::parse($blk->starts_at);
                        $bEnd = Carbon::parse($blk->ends_at);

                        return $cursor->lt($bEnd) && $slotEnd->gt($bStart);
                    });

                    if (! $conflict && ! $blocked) {
                        $key = $cursor->format('Y-m-d\TH:i:s');
                        if (! $freeSlots->has($key)) {
                            $freeSlots->put($key, [
                                'start' => $cursor->format('Y-m-d\TH:i:s'),
                                'end' => $slotEnd->format('Y-m-d\TH:i:s'),
                                'staff_id' => $staffId,
                            ]);
                        }
                    }

                    $cursor->addMinutes(max(1, $stepMinutes));
                }
            }
        }

        return $freeSlots->values()->sortBy('start')->values()->all();
    }
}
