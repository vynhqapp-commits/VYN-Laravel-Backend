<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\TimeOffRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimeOffRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $q = TimeOffRequest::query()->with(['staff:id,name', 'reviewer:id,name']);

        if ($user->hasRole('staff')) {
            $staff = Staff::query()->where('user_id', $user->id)->first();
            if (!$staff) {
                return $this->success([]);
            }
            $q->where('staff_id', $staff->id);
        } else {
            if ($request->filled('staff_id')) {
                $q->where('staff_id', $request->integer('staff_id'));
            }
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return $this->success($q->latest()->limit(200)->get()->map(fn (TimeOffRequest $r) => $this->resource($r))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        try {
            $data = $request->validate([
                'staff_id' => ['required', 'exists:staff,id'],
                'branch_id' => ['nullable', 'exists:branches,id'],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'reason' => ['nullable', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        if ($user->hasRole('staff')) {
            $ownStaff = Staff::query()->where('user_id', $user->id)->first();
            if (!$ownStaff || (int) $ownStaff->id !== (int) $data['staff_id']) {
                return $this->error('Forbidden', 403);
            }
        }

        $timeOff = TimeOffRequest::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'status' => 'pending',
        ]);

        return $this->created($this->resource($timeOff->load(['staff:id,name', 'reviewer:id,name'])));
    }

    public function updateStatus(Request $request, TimeOffRequest $requestItem): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => ['required', Rule::in(['approved', 'rejected'])],
                'decision_note' => ['nullable', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $requestItem->update([
            'status' => $data['status'],
            'decision_note' => $data['decision_note'] ?? null,
            'reviewed_by' => auth('api')->id(),
            'reviewed_at' => now(),
        ]);

        return $this->success($this->resource($requestItem->fresh()->load(['staff:id,name', 'reviewer:id,name'])));
    }

    private function resource(TimeOffRequest $r): array
    {
        return [
            'id' => (string) $r->id,
            'staff_id' => (string) $r->staff_id,
            'staff_name' => $r->staff?->name,
            'branch_id' => $r->branch_id ? (string) $r->branch_id : null,
            'start_date' => optional($r->start_date)->format('Y-m-d'),
            'end_date' => optional($r->end_date)->format('Y-m-d'),
            'reason' => $r->reason,
            'status' => $r->status,
            'reviewed_by' => $r->reviewed_by ? (string) $r->reviewed_by : null,
            'reviewed_by_name' => $r->reviewer?->name,
            'reviewed_at' => optional($r->reviewed_at)->toISOString(),
            'decision_note' => $r->decision_note,
            'created_at' => optional($r->created_at)->toISOString(),
        ];
    }
}
