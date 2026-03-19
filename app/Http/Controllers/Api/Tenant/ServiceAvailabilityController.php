<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceBranchAvailabilityResource;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceBranchAvailability;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceAvailabilityController extends Controller
{
    public function index(Request $request, Service $service)
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
            ]);

            $branch = Branch::findOrFail($data['branch_id']);
            if ($branch->tenant_id !== $service->tenant_id) {
                return $this->forbidden('Branch does not belong to this tenant');
            }

            $rows = ServiceBranchAvailability::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $branch->id)
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get();

            return $this->success(ServiceBranchAvailabilityResource::collection($rows));
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request, Service $service)
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'day_of_week' => 'required|integer|between:0,6',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'slot_minutes' => 'nullable|integer|min:5',
                'is_active' => 'sometimes|boolean',
            ]);

            $branch = Branch::findOrFail($data['branch_id']);
            if ($branch->tenant_id !== $service->tenant_id) {
                return $this->forbidden('Branch does not belong to this tenant');
            }

            // Overlap check for same service+branch+day
            $overlap = ServiceBranchAvailability::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $branch->id)
                ->where('day_of_week', (int) $data['day_of_week'])
                ->where(function ($q) use ($data) {
                    $q->where('start_time', '<', $data['end_time'])
                      ->where('end_time', '>', $data['start_time']);
                })
                ->exists();
            if ($overlap) {
                return $this->validationError([
                    'time' => ['Availability overlaps an existing slot.'],
                ]);
            }

            $row = ServiceBranchAvailability::create([
                'service_id' => $service->id,
                'branch_id' => $branch->id,
                'day_of_week' => (int) $data['day_of_week'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'slot_minutes' => $data['slot_minutes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->created(new ServiceBranchAvailabilityResource($row));
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Service $service, ServiceBranchAvailability $availability)
    {
        try {
            if ($availability->service_id !== $service->id) {
                return $this->notFound('Availability not found for this service');
            }

            $data = $request->validate([
                'day_of_week' => 'sometimes|integer|between:0,6',
                'start_time' => 'sometimes|date_format:H:i',
                'end_time' => 'sometimes|date_format:H:i',
                'slot_minutes' => 'nullable|integer|min:5',
                'is_active' => 'sometimes|boolean',
            ]);

            $next = [
                'day_of_week' => array_key_exists('day_of_week', $data) ? (int) $data['day_of_week'] : $availability->day_of_week,
                'start_time' => $data['start_time'] ?? $availability->start_time,
                'end_time' => $data['end_time'] ?? $availability->end_time,
            ];
            if (strtotime($next['start_time']) >= strtotime($next['end_time'])) {
                return $this->validationError([
                    'end_time' => ['End time must be after start time.'],
                ]);
            }

            $overlap = ServiceBranchAvailability::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $availability->branch_id)
                ->where('day_of_week', (int) $next['day_of_week'])
                ->where('id', '!=', $availability->id)
                ->where(function ($q) use ($next) {
                    $q->where('start_time', '<', $next['end_time'])
                      ->where('end_time', '>', $next['start_time']);
                })
                ->exists();
            if ($overlap) {
                return $this->validationError([
                    'time' => ['Availability overlaps an existing slot.'],
                ]);
            }

            $availability->update($data);

            return $this->success(new ServiceBranchAvailabilityResource($availability), 'Availability updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Service $service, ServiceBranchAvailability $availability)
    {
        try {
            if ($availability->service_id !== $service->id) {
                return $this->notFound('Availability not found for this service');
            }
            $availability->delete();
            return $this->success(null, 'Availability deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

