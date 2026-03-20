<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffScheduleResource;
use App\Models\Staff;
use App\Models\StaffSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function index(Staff $staff)
    {
        try {
            $schedules = $staff->schedules()->orderBy('day_of_week')->get();
            return $this->success(StaffScheduleResource::collection($schedules));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request, Staff $staff)
    {
        try {
            $data = $request->validate([
                'schedules'               => 'required|array',
                'schedules.*.day_of_week' => 'required|integer|between:0,6',
                // Accept both HH:mm and HH:mm:ss (DB returns seconds, frontend may echo them back)
                'schedules.*.start_time'  => 'required_unless:schedules.*.is_day_off,true|date_format:H:i,H:i:s',
                'schedules.*.end_time'    => 'required_unless:schedules.*.is_day_off,true|date_format:H:i,H:i:s',
                'schedules.*.is_day_off'  => 'boolean',
            ]);

            $staff->schedules()->delete();

            $schedules = collect($data['schedules'])->map(function ($s) use ($staff) {
                // Normalize HH:mm:ss → HH:mm and strip any id field from input
                $start = isset($s['start_time']) ? substr($s['start_time'], 0, 5) : '09:00';
                $end   = isset($s['end_time'])   ? substr($s['end_time'],   0, 5) : '18:00';

                return [
                    'staff_id'   => $staff->id,
                    'tenant_id'  => $staff->tenant_id,
                    'day_of_week'=> (int) $s['day_of_week'],
                    'start_time' => $start,
                    'end_time'   => $end,
                    'is_day_off' => (bool) ($s['is_day_off'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            });

            StaffSchedule::insert($schedules->toArray());

            $saved = $staff->schedules()->orderBy('day_of_week')->get();

            return $this->created(StaffScheduleResource::collection($saved), 'Schedules saved');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
