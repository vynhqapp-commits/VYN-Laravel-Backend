<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicSalonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'phone'          => $this->phone,
            'address'        => $this->address,
            'logo'           => $this->logo,
            'timezone'       => $this->timezone,
            'currency'       => $this->currency,
            'gender_preference' => $this->gender_preference,
            'average_rating' => $this->average_rating,
            'distance_km' => $this->when(isset($this->distance_km), fn () => (float) $this->distance_km),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'url' => $photo->url,
                'alt_text' => $photo->alt_text,
                'sort_order' => $photo->sort_order,
            ])->values(), []),
            // Placeholder until FR-C013 review flow is implemented.
            'reviews' => [],
            'branch_count'   => $this->whenLoaded('branches', fn () => $this->branches->count()),
            'service_count'  => $this->whenLoaded('services', fn () => $this->services->count()),
            'branches'       => PublicBranchResource::collection($this->whenLoaded('branches')),
            'services'       => PublicServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
