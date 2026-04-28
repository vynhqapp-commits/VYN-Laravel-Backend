<?php

namespace App\Http\Requests\Api\Tenant;

use App\Services\Appointments\AppointmentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(AppointmentState::STATUSES)],
            'notes' => 'nullable|string',
            'start_time' => 'sometimes|required_with:end_time|date',
            'end_time' => 'sometimes|required_with:start_time|date|after:start_time',
        ];
    }
}
