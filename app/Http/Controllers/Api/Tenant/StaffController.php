<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index()
    {
        try {
            $staff = Staff::with('branch', 'schedules', 'user')->where('is_active', true)->get();
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
            ]);

            $staff = Staff::create($data);
            $staff->load('branch', 'schedules', 'user');

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
            return $this->success(new StaffResource($staff->load('branch', 'schedules', 'user', 'commissionRules')));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Staff $staff)
    {
        try {
            $staff->update($request->validate([
                'branch_id'      => 'sometimes|exists:branches,id',
                'name'           => 'sometimes|string|max:255',
                'phone'          => 'nullable|string',
                'specialization' => 'nullable|string',
                'is_active'      => 'sometimes|boolean',
            ]));

            return $this->success(new StaffResource($staff->load('branch', 'schedules', 'user')), 'Staff updated');

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
}
