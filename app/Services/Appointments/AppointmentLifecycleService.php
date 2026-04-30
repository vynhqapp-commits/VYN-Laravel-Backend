<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\ApprovalRequest;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentLifecycleService
{
    public function __construct(
        private readonly AppointmentSchedulingService $scheduling,
        private readonly AppointmentInventoryCompletionService $inventoryCompletion,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated store payload
     */
    public function create(array $data, ?int $tenantId): Appointment
    {
        $service = Service::findOrFail($data['service_id']);
        $staffId = $data['staff_id'] ?? Staff::where('branch_id', $data['branch_id'])->value('id');
        if (! $staffId) {
            throw new \DomainException('No staff available');
        }

        $startsAt = Carbon::parse($data['start_time']);
        $endsAt = Carbon::parse($data['end_time']);

        $this->scheduling->assertSlotAvailable((int) $data['branch_id'], (int) $staffId, $startsAt, $endsAt);

        return DB::transaction(function () use ($data, $tenantId, $service, $staffId, $startsAt, $endsAt) {
            $appointment = Appointment::create([
                'tenant_id' => $tenantId,
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'staff_id' => $staffId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $data['status'] ?? 'scheduled',
                'source' => $data['source'] ?? 'dashboard',
                'notes' => $data['notes'] ?? null,
            ]);

            AppointmentService::create([
                'appointment_id' => $appointment->id,
                'service_id' => $service->id,
                'price' => $service->price,
                'duration_minutes' => $service->duration_minutes,
            ]);

            return $appointment->load(['branch', 'customer', 'staff', 'services.service']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated update payload
     */
    public function update(Appointment $appointment, array $data, ?int $tenantId): Appointment
    {
        $isReschedule = array_key_exists('start_time', $data) || array_key_exists('end_time', $data);

        if (array_key_exists('status', $data)) {
            $from = (string) $appointment->status;
            $to = (string) $data['status'];
            if (! AppointmentState::allowsTransition($from, $to)) {
                throw new \DomainException("Invalid status transition: {$from} → {$to}");
            }
        }

        if ($isReschedule) {
            if (! in_array((string) $appointment->status, AppointmentState::reschedulableStatuses(), true)) {
                throw new \DomainException('Appointment cannot be rescheduled in its current status.');
            }
        }

        $nextStarts = null;
        $nextEnds = null;
        if ($isReschedule) {
            $nextStarts = Carbon::parse((string) $data['start_time']);
            $nextEnds = Carbon::parse((string) $data['end_time']);
            $this->scheduling->assertSlotAvailable(
                (int) $appointment->branch_id,
                (int) $appointment->staff_id,
                $nextStarts,
                $nextEnds,
                (int) $appointment->id,
            );
        }

        return DB::transaction(function () use ($appointment, $data, $tenantId, $isReschedule, $nextStarts, $nextEnds) {
            if (
                array_key_exists('status', $data)
                && (string) $data['status'] === 'completed'
                && (string) $appointment->status !== 'completed'
            ) {
                if (! $tenantId) {
                    throw new \RuntimeException('Tenant required');
                }
                $this->inventoryCompletion->deductForCompletion($appointment, $tenantId);
            }

            $updateData = $data;
            if ($isReschedule && $nextStarts && $nextEnds) {
                $updateData['starts_at'] = $nextStarts;
                $updateData['ends_at'] = $nextEnds;
                unset($updateData['start_time'], $updateData['end_time']);
            }

            $appointment->update($updateData);

            return $appointment->fresh()->load(['branch', 'customer', 'staff', 'services.service']);
        });
    }

    /**
     * @return array{type: 'deleted'}|array{type: 'approval_pending', approval: ApprovalRequest}
     */
    public function destroy(Appointment $appointment, mixed $user): array
    {
        if ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['receptionist', 'staff'])) {
            $existing = ApprovalRequest::query()
                ->where('entity_type', 'appointment')
                ->where('entity_id', (int) $appointment->id)
                ->where('requested_action', 'delete')
                ->where('status', ApprovalRequest::STATUS_PENDING)
                ->latest('id')
                ->first();

            if ($existing) {
                return ['type' => 'approval_pending', 'approval' => $existing];
            }

            $req = ApprovalRequest::create([
                'tenant_id' => (int) ($user->tenant_id ?? 0),
                'branch_id' => (int) $appointment->branch_id,
                'entity_type' => 'appointment',
                'entity_id' => (int) $appointment->id,
                'requested_action' => 'delete',
                'requested_by' => (int) $user->id,
                'payload' => null,
                'status' => ApprovalRequest::STATUS_PENDING,
                'expires_at' => now()->addDays(7),
            ]);

            return ['type' => 'approval_pending', 'approval' => $req];
        }

        $appointment->delete();

        return ['type' => 'deleted'];
    }
}
