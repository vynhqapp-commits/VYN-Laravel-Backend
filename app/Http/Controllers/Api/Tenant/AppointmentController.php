<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\IndexAppointmentsRequest;
use App\Http\Requests\Api\Tenant\StoreAppointmentRequest;
use App\Http\Requests\Api\Tenant\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Services\Appointments\AppointmentLifecycleService;
use App\Services\Appointments\AppointmentQueryService;
use Illuminate\Http\JsonResponse;

/**
 * @group Appointments
 *
 * Tenant appointment lifecycle APIs.
 *
 * @authenticated
 * @header X-Tenant string required Tenant identifier (ID or slug). Example: 1
 */
class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentQueryService $appointmentQuery,
        private readonly AppointmentLifecycleService $appointmentLifecycle,
    ) {}

    public function index(IndexAppointmentsRequest $request): JsonResponse
    {
        try {
            return $this->paginated(
                $this->appointmentQuery->paginate($request->validated())
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Appointment $appointment): JsonResponse
    {
        try {
            return $this->success($appointment->load(['branch', 'customer', 'staff', 'services.service']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;

            return $this->created(
                $this->appointmentLifecycle->create($request->validated(), $tenantId !== null ? (int) $tenantId : null)
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;

            $updated = $this->appointmentLifecycle->update(
                $appointment,
                $request->validated(),
                $tenantId !== null ? (int) $tenantId : null
            );

            return $this->success($updated, 'Appointment updated');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        try {
            $result = $this->appointmentLifecycle->destroy($appointment, auth('api')->user());

            if ($result['type'] === 'approval_pending') {
                return $this->success($result['approval'], 'Deletion request submitted for approval', 202);
            }

            return $this->success(null, 'Appointment cancelled');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
