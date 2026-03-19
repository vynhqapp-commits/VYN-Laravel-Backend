<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class CustomerBookingController extends Controller
{
    public function index(): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (!$customer) {
            return $this->success(['bookings' => []]);
        }

        $bookings = Appointment::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->with(['branch', 'staff', 'services.service'])
            ->latest('starts_at')
            ->get();

        return $this->success(['bookings' => $bookings]);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) return $owned;

        return $this->success(['booking' => $appointment->load(['branch', 'staff', 'services.service'])]);
    }

    public function cancel(Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) return $owned;

        if (!in_array((string) $appointment->status, ['scheduled', 'confirmed', 'pending'], true)) {
            return $this->error('Only scheduled bookings can be cancelled', 422);
        }

        $startAt = Carbon::parse($appointment->starts_at);
        if ($startAt->isPast()) {
            return $this->error('Past bookings cannot be cancelled', 422);
        }

        $appointment->update(['status' => 'cancelled']);

        return $this->success(['booking' => $appointment->fresh()->load(['branch', 'staff', 'services.service'])], 'Booking cancelled');
    }

    private function ownedByCustomer(Appointment $appointment): true|JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (!$customer) return $this->error('Customer profile not found', 404);

        if ((int) $appointment->tenant_id !== (int) $tenantId || (int) $appointment->customer_id !== (int) $customer->id) {
            return $this->error('Not found', 404);
        }

        return true;
    }
}
