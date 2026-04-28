<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\TimeBlock;
use Carbon\CarbonInterface;

class AppointmentSchedulingService
{
    public function assertSlotAvailable(
        int $branchId,
        int $staffId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreAppointmentId = null,
    ): void {
        if ($this->hasStaffBookingConflict($branchId, $staffId, $startsAt, $endsAt, $ignoreAppointmentId)) {
            throw new \DomainException('Staff is already booked for this time.');
        }

        if ($this->hasTimeBlockOverlap($branchId, $staffId, $startsAt, $endsAt)) {
            throw new \DomainException('Selected time is blocked.');
        }
    }

    public function hasStaffBookingConflict(
        int $branchId,
        int $staffId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreAppointmentId = null,
    ): bool {
        $q = Appointment::query()
            ->where('branch_id', $branchId)
            ->where('staff_id', $staffId)
            ->whereIn('status', AppointmentState::blockingBookingStatuses())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($ignoreAppointmentId !== null) {
            $q->where('id', '!=', $ignoreAppointmentId);
        }

        return $q->exists();
    }

    public function hasTimeBlockOverlap(
        int $branchId,
        int $staffId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        return TimeBlock::query()
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($staffId) {
                $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
            })
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
