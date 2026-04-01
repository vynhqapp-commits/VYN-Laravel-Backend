<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBookingController extends Controller
{
    private const POLICY_WINDOW_HOURS = 24;

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
                'review',
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
                'review',
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

        $policy = $this->buildPolicyMeta($startAt);
        $appointment->update(['status' => 'cancelled']);

        $payload = [
            'booking' => $appointment->fresh()->load([
                'branch'  => fn ($q) => $q->withoutGlobalScopes(),
                'staff'   => fn ($q) => $q->withoutGlobalScopes(),
                'services' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['service' => fn ($sq) => $sq->withoutGlobalScopes()]),
                'review',
            ]),
            'policy' => $policy,
        ];

        if ($policy['violated']) {
            $payload['warnings'] = [
                "Cancelled inside {$policy['window_hours']}h policy window.",
            ];
        }

        return $this->success($payload, 'Booking cancelled');
    }

    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) return $owned;

        if (!in_array((string) $appointment->status, ['scheduled', 'confirmed', 'pending'], true)) {
            return $this->error('Only scheduled or confirmed bookings can be rescheduled', 422);
        }

        try {
            $data = $request->validate([
                'start_at' => 'required|date|after:now',
                'staff_id' => 'nullable|exists:staff,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $currentStartAt = Carbon::parse($appointment->starts_at);
        if ($currentStartAt->isPast()) {
            return $this->error('Past bookings cannot be rescheduled', 422);
        }

        $newStartAt = Carbon::parse($data['start_at']);
        $durationMinutes = (int) ($appointment->services()->withoutGlobalScopes()->value('duration_minutes') ?? 0);
        if ($durationMinutes <= 0) {
            $durationMinutes = max(1, $currentStartAt->diffInMinutes(Carbon::parse($appointment->ends_at)));
        }
        $newEndAt = $newStartAt->copy()->addMinutes($durationMinutes);

        $staffId = $data['staff_id'] ?? $appointment->staff_id;
        if (!empty($data['staff_id'])) {
            $staffOk = Staff::withoutGlobalScopes()
                ->whereKey($staffId)
                ->where('tenant_id', $appointment->tenant_id)
                ->where('branch_id', $appointment->branch_id)
                ->where('is_active', true)
                ->exists();
            if (!$staffOk) {
                return $this->error('Selected staff is not available for this branch.', 422);
            }
        }

        $conflictExists = Appointment::withoutGlobalScopes()
            ->where('tenant_id', $appointment->tenant_id)
            ->where('branch_id', $appointment->branch_id)
            ->where('staff_id', $staffId)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('id', '!=', $appointment->id)
            ->where('starts_at', '<', $newEndAt)
            ->where('ends_at', '>', $newStartAt)
            ->exists();

        if ($conflictExists) {
            return $this->error('Selected slot is no longer available.', 422);
        }

        $policy = $this->buildPolicyMeta($currentStartAt);

        $appointment->update([
            'staff_id' => $staffId,
            'starts_at' => $newStartAt,
            'ends_at' => $newEndAt,
        ]);

        $payload = [
            'booking' => $appointment->fresh()->load([
                'branch'  => fn ($q) => $q->withoutGlobalScopes(),
                'staff'   => fn ($q) => $q->withoutGlobalScopes(),
                'services' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['service' => fn ($sq) => $sq->withoutGlobalScopes()]),
                'review',
            ]),
            'policy' => $policy,
        ];

        if ($policy['violated']) {
            $payload['warnings'] = [
                "Rescheduled inside {$policy['window_hours']}h policy window.",
            ];
        }

        return $this->success(
            $payload,
            'Booking rescheduled'
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

    private function buildPolicyMeta(Carbon $startsAt): array
    {
        $minutesToStart = Carbon::now()->diffInMinutes($startsAt, false);
        $thresholdMinutes = self::POLICY_WINDOW_HOURS * 60;

        return [
            'window_hours' => self::POLICY_WINDOW_HOURS,
            'minutes_to_start' => $minutesToStart,
            'violated' => $minutesToStart >= 0 && $minutesToStart < $thresholdMinutes,
            'mode' => 'soft',
        ];
    }
}
