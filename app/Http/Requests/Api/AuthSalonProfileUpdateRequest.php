<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AuthSalonProfileUpdateRequest extends FormRequest
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
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:100',
            'currency' => 'nullable|string|size:3',
            'logo' => 'nullable|string|max:500',
            'gender_preference' => 'nullable|in:ladies,gents,unisex',
            'cancellation_window_hours' => 'nullable|integer|min:0|max:168',
            'cancellation_policy_mode' => 'nullable|in:soft,hard,none',
        ];
    }
}
