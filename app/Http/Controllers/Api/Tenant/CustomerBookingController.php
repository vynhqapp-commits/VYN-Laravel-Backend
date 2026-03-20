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
        $userId = auth('api')->id();
        if (!$userId) return $this->error('Unauthenticated', 401);

        $userEmail = auth('api')->user()?->email;

        // Auto-link any unlinked Customer records that share the same email —
        // this makes pre-registration guest bookings appear immediately after sign-up.
        if ($userEmail) {
            Customer::withoutGlobalScopes()
                ->whereNull('user_id')
                ->where('email', $userEmail)
                ->update(['user_id' => $userId]);
        }

        // Collect Customer IDs owned by this user (across all tenants)
        $customerIds = Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('id');

        if ($customerIds->isEmpty()) {
            return $this->success(['bookings' => []]);
        }

        // Eager-load relations with withoutGlobalScopes() so tenant-scoped models
        // resolve correctly for bookings that belong to any tenant.
        $bookings = Appointment::withoutGlobalScopes()
            ->whereIn('customer_id', $customerIds)
            ->with([
                'branch'  => fn ($q) => $q->withoutGlobalScopes(),
                'staff'   => fn ($q) => $q->withoutGlobalScopes(),
                'services' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['service' => fn ($sq) => $sq->withoutGlobalScopes()]),
            ])
            ->latest('starts_at')
            ->get();

        return $this->success(['bookings' => $bookings]);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) return $owned;

        return $this->success([
            'booking' => $appointment->load([
                'branch'  => fn ($q) => $q->withoutGlobalScopes(),
                'staff'   => fn ($q) => $q->withoutGlobalScopes(),
                'services' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['service' => fn ($sq) => $sq->withoutGlobalScopes()]),
            ]),
        ]);
    }

    public function cancel(Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) return $owned;

        if (!in_array((string) $appointment->status, ['scheduled', 'confirmed', 'pending'], true)) {
            return $this->error('Only scheduled or confirmed bookings can be cancelled', 422);
        }

        $startAt = Carbon::parse($appointment->starts_at);
        if ($startAt->isPast()) {
            return $this->error('Past bookings cannot be cancelled', 422);
        }

        $appointment->update(['status' => 'cancelled']);

        return $this->success(
            [
                'booking' => $appointment->fresh()->load([
                    'branch'  => fn ($q) => $q->withoutGlobalScopes(),
                    'staff'   => fn ($q) => $q->withoutGlobalScopes(),
                    'services' => fn ($q) => $q->withoutGlobalScopes()
                        ->with(['service' => fn ($sq) => $sq->withoutGlobalScopes()]),
                ]),
            ],
            'Booking cancelled'
        );
    }

    private function ownedByCustomer(Appointment $appointment): true|JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) return $this->error('Unauthenticated', 401);

        $ownsCustomer = Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('id', $appointment->customer_id)
            ->exists();

        if (!$ownsCustomer) {
            return $this->error('Not found', 404);
        }

        return true;
    }
}
