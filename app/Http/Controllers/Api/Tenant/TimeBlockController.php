<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeBlockResource;
use App\Models\Branch;
use App\Models\Staff;
use App\Models\TimeBlock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimeBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'staff_id' => 'nullable|exists:staff,id',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $branch->tenant_id !== (int) $tenantId) return $this->forbidden('Branch does not belong to this tenant');

            $q = TimeBlock::query()
                ->where('branch_id', $branch->id)
                ->orderBy('starts_at');

            if (!empty($data['staff_id'])) {
                $q->where(function ($qq) use ($data) {
                    $qq->whereNull('staff_id')->orWhere('staff_id', $data['staff_id']);
                });
            }

            if (!empty($data['from']) || !empty($data['to'])) {
                $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
                $to = !empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;
                if ($from && $to) $q->whereBetween('starts_at', [$from, $to]);
                elseif ($from) $q->where('starts_at', '>=', $from);
                elseif ($to) $q->where('starts_at', '<=', $to);
            }

            $rows = $q->get();
            return $this->success(TimeBlockResource::collection($rows));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'staff_id' => 'nullable|exists:staff,id',
                'starts_at' => 'required|date',
                'ends_at' => 'required|date|after:starts_at',
                'reason' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            $branch = Branch::query()->findOrFail($data['branch_id']);
            if ((int) $branch->tenant_id !== (int) $tenantId) return $this->forbidden('Branch does not belong to this tenant');

            $staffId = $data['staff_id'] ?? null;
            if ($staffId) {
                $staff = Staff::query()->findOrFail($staffId);
                if ((int) $staff->tenant_id !== (int) $tenantId || (int) $staff->branch_id !== (int) $branch->id) {
                    return $this->validationError(['staff_id' => ['Invalid staff for branch/tenant.']]);
                }
            }

            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);

            // Overlap check:
            // - A staff-specific block cannot overlap another staff-specific block for same staff
            // - A branch-wide block (staff_id null) cannot overlap any block in that branch
            $overlap = TimeBlock::query()
                ->where('branch_id', $branch->id)
                ->where(function ($q) use ($staffId) {
                    if ($staffId) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
                    } else {
                        $q->whereRaw('1=1');
                    }
                })
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();
            if ($overlap) {
                return $this->validationError(['time' => ['Time block overlaps an existing block.']]);
            }

            $row = TimeBlock::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branch->id,
                'staff_id' => $staffId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $data['reason'] ?? null,
                'created_by' => auth('api')->id(),
            ]);

            return $this->created(new TimeBlockResource($row));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, TimeBlock $timeBlock): JsonResponse
    {
        try {
            $data = $request->validate([
                'staff_id' => 'nullable|exists:staff,id',
                'starts_at' => 'sometimes|date',
                'ends_at' => 'sometimes|date',
                'reason' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $timeBlock->tenant_id !== (int) $tenantId) return $this->notFound('Time block not found');

            $branch = Branch::query()->findOrFail($timeBlock->branch_id);

            $nextStaffId = array_key_exists('staff_id', $data) ? ($data['staff_id'] ?? null) : $timeBlock->staff_id;
            if ($nextStaffId) {
                $staff = Staff::query()->findOrFail($nextStaffId);
                if ((int) $staff->tenant_id !== (int) $tenantId || (int) $staff->branch_id !== (int) $branch->id) {
                    return $this->validationError(['staff_id' => ['Invalid staff for branch/tenant.']]);
                }
            }

            $nextStarts = array_key_exists('starts_at', $data) ? Carbon::parse($data['starts_at']) : Carbon::parse($timeBlock->starts_at);
            $nextEnds = array_key_exists('ends_at', $data) ? Carbon::parse($data['ends_at']) : Carbon::parse($timeBlock->ends_at);
            if ($nextStarts->gte($nextEnds)) {
                return $this->validationError(['ends_at' => ['End must be after start.']]);
            }

            $overlap = TimeBlock::query()
                ->where('branch_id', $branch->id)
                ->where('id', '!=', $timeBlock->id)
                ->where(function ($q) use ($nextStaffId) {
                    if ($nextStaffId) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $nextStaffId);
                    } else {
                        $q->whereRaw('1=1');
                    }
                })
                ->where('starts_at', '<', $nextEnds)
                ->where('ends_at', '>', $nextStarts)
                ->exists();
            if ($overlap) {
                return $this->validationError(['time' => ['Time block overlaps an existing block.']]);
            }

            $timeBlock->update([
                'staff_id' => $nextStaffId,
                'starts_at' => $nextStarts,
                'ends_at' => $nextEnds,
                'reason' => array_key_exists('reason', $data) ? ($data['reason'] ?? null) : $timeBlock->reason,
            ]);

            return $this->success(new TimeBlockResource($timeBlock), 'Time block updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(TimeBlock $timeBlock): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $timeBlock->tenant_id !== (int) $tenantId) return $this->notFound('Time block not found');

            $timeBlock->delete();
            return $this->success(null, 'Time block deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

