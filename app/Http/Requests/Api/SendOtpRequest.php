<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email') ?? $this->input('identifier');
        $this->merge([
            'identifier' => $email,
            'type' => $this->input('type', 'email'),
            'purpose' => $this->input('purpose', 'login'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => 'required|string',
            'type' => 'required|in:phone,email',
            'purpose' => 'required|in:login,register,reset_password',
        ];
    }
}
