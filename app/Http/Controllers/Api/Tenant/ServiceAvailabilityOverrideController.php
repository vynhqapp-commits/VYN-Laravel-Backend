<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceBranchAvailabilityOverrideResource;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceBranchAvailabilityOverride;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceAvailabilityOverrideController extends Controller
{
    public function index(Request $request, Service $service)
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d',
            ]);

            $branch = Branch::findOrFail($data['branch_id']);
            if ($branch->tenant_id !== $service->tenant_id) {
                return $this->forbidden('Branch does not belong to this tenant');
            }

            $q = ServiceBranchAvailabilityOverride::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $branch->id)
                ->orderBy('date')
                ->orderBy('start_time');

            if (!empty($data['from'])) $q->where('date', '>=', $data['from']);
            if (!empty($data['to'])) $q->where('date', '<=', $data['to']);

            $rows = $q->get();
            return $this->success(ServiceBranchAvailabilityOverrideResource::collection($rows));
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
                'date' => 'required|date_format:Y-m-d',
                'is_closed' => 'sometimes|boolean',
                'start_time' => 'nullable|date_format:H:i',
                'end_time' => 'nullable|date_format:H:i',
                'slot_minutes' => 'nullable|integer|min:5',
            ]);

            $branch = Branch::findOrFail($data['branch_id']);
            if ($branch->tenant_id !== $service->tenant_id) {
                return $this->forbidden('Branch does not belong to this tenant');
            }

            $isClosed = (bool) ($data['is_closed'] ?? false);
            if ($isClosed) {
                $data['start_time'] = null;
                $data['end_time'] = null;
            } else {
                if (empty($data['start_time']) || empty($data['end_time'])) {
                    return $this->validationError([
                        'time' => ['Start and end time are required unless the day is closed.'],
                    ]);
                }
                if (strtotime($data['start_time']) >= strtotime($data['end_time'])) {
                    return $this->validationError([
                        'end_time' => ['End time must be after start time.'],
                    ]);
                }
            }

            // Overlap check for same service+branch+date
            $overlap = ServiceBranchAvailabilityOverride::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $branch->id)
                ->where('date', $data['date'])
                ->where(function ($q) use ($data, $isClosed) {
                    if ($isClosed) {
                        // closed overlaps anything for that date
                        $q->whereRaw('1=1');
                    } else {
                        $q->where('is_closed', true)
                          ->orWhere(function ($q2) use ($data) {
                              $q2->where('start_time', '<', $data['end_time'])
                                 ->where('end_time', '>', $data['start_time']);
                          });
                    }
                })
                ->exists();
            if ($overlap) {
                return $this->validationError([
                    'time' => ['Override overlaps an existing override for this date.'],
                ]);
            }

            $row = ServiceBranchAvailabilityOverride::create([
                'service_id' => $service->id,
                'branch_id' => $branch->id,
                'date' => $data['date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'slot_minutes' => $data['slot_minutes'] ?? null,
                'is_closed' => $isClosed,
            ]);

            return $this->created(new ServiceBranchAvailabilityOverrideResource($row));
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Service $service, ServiceBranchAvailabilityOverride $override)
    {
        try {
            if ($override->service_id !== $service->id) {
                return $this->notFound('Override not found for this service');
            }

            $data = $request->validate([
                'date' => 'sometimes|date_format:Y-m-d',
                'is_closed' => 'sometimes|boolean',
                'start_time' => 'nullable|date_format:H:i',
                'end_time' => 'nullable|date_format:H:i',
                'slot_minutes' => 'nullable|integer|min:5',
            ]);

            $nextDate = $data['date'] ?? ($override->getRawOriginal('date') ?: null);
            $nextClosed = array_key_exists('is_closed', $data) ? (bool) $data['is_closed'] : (bool) $override->is_closed;
            $nextStart = array_key_exists('start_time', $data) ? $data['start_time'] : $override->start_time;
            $nextEnd = array_key_exists('end_time', $data) ? $data['end_time'] : $override->end_time;

            if ($nextClosed) {
                $nextStart = null;
                $nextEnd = null;
                $data['start_time'] = null;
                $data['end_time'] = null;
            } else {
                if (!$nextStart || !$nextEnd) {
                    return $this->validationError([
                        'time' => ['Start and end time are required unless the day is closed.'],
                    ]);
                }
                if (strtotime($nextStart) >= strtotime($nextEnd)) {
                    return $this->validationError([
                        'end_time' => ['End time must be after start time.'],
                    ]);
                }
            }

            $overlap = ServiceBranchAvailabilityOverride::query()
                ->where('service_id', $service->id)
                ->where('branch_id', $override->branch_id)
                ->where('date', $nextDate)
                ->where('id', '!=', $override->id)
                ->where(function ($q) use ($nextStart, $nextEnd, $nextClosed) {
                    if ($nextClosed) {
                        $q->whereRaw('1=1');
                    } else {
                        $q->where('is_closed', true)
                          ->orWhere(function ($q2) use ($nextStart, $nextEnd) {
                              $q2->where('start_time', '<', $nextEnd)
                                 ->where('end_time', '>', $nextStart);
                          });
                    }
                })
                ->exists();
            if ($overlap) {
                return $this->validationError([
                    'time' => ['Override overlaps an existing override for this date.'],
                ]);
            }

            $override->update($data);
            return $this->success(new ServiceBranchAvailabilityOverrideResource($override), 'Override updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Service $service, ServiceBranchAvailabilityOverride $override)
    {
        try {
            if ($override->service_id !== $service->id) {
                return $this->notFound('Override not found for this service');
            }
            $override->delete();
            return $this->success(null, 'Override deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

