<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SalonListFilterRules;
use Illuminate\Foundation\Http\FormRequest;

class NearbySalonsRequest extends FormRequest
{
    use SalonListFilterRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], $this->salonListFilterRules());
    }
}
