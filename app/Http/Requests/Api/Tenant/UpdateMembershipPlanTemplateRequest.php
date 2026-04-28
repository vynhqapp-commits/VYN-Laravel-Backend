<?php

namespace App\Http\Requests\Api\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMembershipPlanTemplateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'sometimes|numeric|min:0',
            'interval_months' => 'sometimes|integer|min:1',
            'credits_per_renewal' => 'sometimes|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }
}
