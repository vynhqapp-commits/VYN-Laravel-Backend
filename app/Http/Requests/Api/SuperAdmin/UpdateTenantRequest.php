<?php

namespace App\Http\Requests\Api\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
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
        $tenant = $this->route('tenant');

        return [
            'name' => 'sometimes|string|max:255',
            'domain' => ['sometimes', 'string', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
            'plan' => 'sometimes|in:basic,pro,enterprise',
            'timezone' => 'sometimes|string',
            'currency' => 'sometimes|string|size:3',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
        ];
    }
}
