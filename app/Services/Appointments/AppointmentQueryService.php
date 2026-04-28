<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AppointmentQueryService
{
    /**
     * @param  array<string, mixed>  $filters  validated index filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $q = Appointment::query()->with(['branch', 'customer', 'staff', 'services.service'])->latest('starts_at');

        if (! empty($filters['branch_id'])) {
            $q->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['staff_id'])) {
            $q->where('staff_id', $filters['staff_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['date'])) {
            $q->whereDate('starts_at', $filters['date']);
        }

        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $to = Carbon::parse($filters['to'])->endOfDay();
            $q->whereBetween('starts_at', [$from, $to]);
        } elseif (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $q->where('starts_at', '>=', $from);
        } elseif (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->endOfDay();
            $q->where('starts_at', '<=', $to);
        }

        return $q->paginate(100);
    }
}
