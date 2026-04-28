<?php

namespace App\Http\Requests\Api\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
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
            'phone' => 'nullable|string',
            'contact_email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'timezone' => 'nullable|string',
            'working_hours' => 'nullable|string|max:4000',
            'gender_preference' => 'nullable|in:ladies,gents,unisex',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
