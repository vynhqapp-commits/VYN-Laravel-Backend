<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSalonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],

            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::when($this->filled('price_min'), ['gte:price_min']),
            ],

            'rating_min' => ['nullable', 'numeric', 'min:0', 'max:5'],

            'availability' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],

            'gender_preference' => ['nullable', 'in:ladies,gents,unisex'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}

