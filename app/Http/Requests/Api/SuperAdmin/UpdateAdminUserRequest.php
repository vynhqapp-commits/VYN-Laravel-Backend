<?php

namespace App\Http\Requests\Api\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminUserRequest extends FormRequest
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
            'name' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|exists:tenants,id',
            'role' => 'nullable|in:super_admin,salon_owner,manager,staff,customer',
            'password' => 'nullable|string|min:8',
        ];
    }
}
