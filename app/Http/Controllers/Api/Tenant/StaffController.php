<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index()
    {
        try {
            $staff = Staff::with('branch', 'schedules', 'user', 'services')->where('is_active', true)->get();
            return $this->success(StaffResource::collection($staff));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'branch_id'      => 'required|exists:branches,id',
                'user_id'        => 'nullable|exists:users,id',
                'name'           => 'required|string|max:255',
                'phone'          => 'nullable|string',
                'specialization' => 'nullable|string',
                'photo_url'      => 'nullable|url|max:2048',
                'service_ids'    => 'nullable|array',
                'service_ids.*'  => 'integer|exists:services,id',
                'color'          => ['nullable', 'string', 'max:16', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            ]);

            $serviceIds = $data['service_ids'] ?? [];
            unset($data['service_ids']);

            $staff = Staff::create($data);
            if (!empty($serviceIds)) {
                $attachData = collect($serviceIds)->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $staff->tenant_id]])->all();
                $staff->services()->sync($attachData);
            }
            $staff->load('branch', 'schedules', 'user', 'services');

            return $this->created(new StaffResource($staff));

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Staff $staff)
    {
        try {
            return $this->success(new StaffResource($staff->load('branch', 'schedules', 'user', 'commissionRules', 'services')));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Staff $staff)
    {
        try {
            $data = $request->validate([
                'branch_id'      => 'sometimes|exists:branches,id',
                'name'           => 'sometimes|string|max:255',
                'phone'          => 'nullable|string',
                'specialization' => 'nullable|string',
                'photo_url'      => 'nullable|url|max:2048',
                'service_ids'    => 'nullable|array',
                'service_ids.*'  => 'integer|exists:services,id',
                'color'          => ['nullable', 'string', 'max:16', 'regex:/^#?[0-9a-fA-F]{6}$/'],
                'is_active'      => 'sometimes|boolean',
            ]);

            $serviceIds = $data['service_ids'] ?? null;
            unset($data['service_ids']);

            $staff->update($data);
            if ($serviceIds !== null) {
                $attachData = collect($serviceIds)->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $staff->tenant_id]])->all();
                $staff->services()->sync($attachData);
            }

            return $this->success(new StaffResource($staff->load('branch', 'schedules', 'user', 'services')), 'Staff updated');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Staff $staff)
    {
        try {
            $staff->update(['is_active' => false]);
            return $this->success(null, 'Staff deactivated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function performance(Request $request)
    {
        try {
            $data = $request->validate([
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $from = $data['from'] ?? null;
            $to = $data['to'] ?? null;

            $appointments = DB::table('appointments')
                ->selectRaw('staff_id, COUNT(*) as total_appointments, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_appointments, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as no_shows', ['completed', 'no_show'])
                ->when($from, fn ($q) => $q->whereDate('starts_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('starts_at', '<=', $to))
                ->groupBy('staff_id');

            $revenue = DB::table('invoices')
                ->join('appointments', 'appointments.id', '=', 'invoices.appointment_id')
                ->selectRaw('appointments.staff_id as staff_id, SUM(invoices.total) as revenue')
                ->where('invoices.status', 'paid')
                ->when($from, fn ($q) => $q->whereDate('invoices.created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('invoices.created_at', '<=', $to))
                ->groupBy('appointments.staff_id');

            $rows = Staff::query()
                ->leftJoinSub($appointments, 'app_stats', 'app_stats.staff_id', '=', 'staff.id')
                ->leftJoinSub($revenue, 'rev_stats', 'rev_stats.staff_id', '=', 'staff.id')
                ->selectRaw('staff.id, staff.name, COALESCE(app_stats.total_appointments, 0) as total_appointments, COALESCE(app_stats.completed_appointments, 0) as completed_appointments, COALESCE(app_stats.no_shows, 0) as no_shows, COALESCE(rev_stats.revenue, 0) as revenue')
                ->where('staff.is_active', true)
                ->orderByDesc('revenue')
                ->get()
                ->map(function ($r) {
                    $total = (int) $r->total_appointments;
                    $completed = (int) $r->completed_appointments;
                    return [
                        'staff_id' => (string) $r->id,
                        'staff_name' => $r->name,
                        'revenue' => (float) $r->revenue,
                        'total_appointments' => $total,
                        'completed_appointments' => $completed,
                        'no_shows' => (int) $r->no_shows,
                        'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0.0,
                    ];
                })
                ->values();

            return $this->success($rows);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
