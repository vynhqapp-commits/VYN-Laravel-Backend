<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffTimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffTimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffTimeEntry::query()->with('staff:id,name')->latest('clock_in_at');
        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->integer('staff_id'));
        }

        return $this->success($query->limit(200)->get()->map(fn (StaffTimeEntry $e) => $this->resource($e))->values());
    }

    public function clockIn(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'staff_id' => 'required|exists:staff,id',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $staff = Staff::query()->findOrFail((int) $data['staff_id']);
        $open = StaffTimeEntry::query()
            ->where('staff_id', $staff->id)
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();
        if ($open) {
            return $this->error('Staff already clocked in', 422);
        }

        $entry = StaffTimeEntry::create([
            'tenant_id' => auth('api')->user()->tenant_id,
            'staff_id' => $staff->id,
            'branch_id' => $data['branch_id'] ?? $staff->branch_id,
            'clock_in_at' => now(),
        ]);

        return $this->created($this->resource($entry->load('staff:id,name')));
    }

    public function clockOut(StaffTimeEntry $entry): JsonResponse
    {
        if ($entry->clock_out_at) {
            return $this->error('Entry already closed', 422);
        }
        $entry->update(['clock_out_at' => now()]);
        return $this->success($this->resource($entry->fresh()->load('staff:id,name')));
    }

    private function resource(StaffTimeEntry $e): array
    {
        return [
            'id' => (string) $e->id,
            'staff_id' => (string) $e->staff_id,
            'staff_name' => $e->staff?->name,
            'branch_id' => $e->branch_id ? (string) $e->branch_id : null,
            'clock_in_at' => optional($e->clock_in_at)->toISOString(),
            'clock_out_at' => optional($e->clock_out_at)->toISOString(),
            'created_at' => optional($e->created_at)->toISOString(),
        ];
    }
}
