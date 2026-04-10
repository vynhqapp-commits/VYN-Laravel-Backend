<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait SalonListFilterRules
{
    /**
     * Shared query rules for public salon list + nearby (price, rating, availability, gender).
     */
    protected function salonListFilterRules(): array
    {
        return [
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
        ];
    }
}
