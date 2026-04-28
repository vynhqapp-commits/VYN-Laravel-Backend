<?php

namespace App\Http\Requests\Api\Tenant;

use App\Services\Appointments\AppointmentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'staff_id' => 'nullable|exists:staff,id',
            'service_id' => 'required|exists:services,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'notes' => 'nullable|string',
            'status' => ['nullable', Rule::in(AppointmentState::STATUSES)],
            'source' => 'nullable|in:dashboard,walk_in,public,online',
        ];
    }
}
