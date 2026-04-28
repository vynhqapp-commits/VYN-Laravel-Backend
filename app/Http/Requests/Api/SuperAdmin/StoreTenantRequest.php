<?php

namespace App\Http\Requests\Api\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|unique:tenants',
            'plan' => 'nullable|in:basic,pro,enterprise',
            'timezone' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
        ];
    }
}
