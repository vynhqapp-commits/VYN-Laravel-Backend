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
            'branch_count'   => $this->whenLoaded('branches', fn () => $this->branches->count()),
            'service_count'  => $this->whenLoaded('services', fn () => $this->services->count()),
            'branches'       => PublicBranchResource::collection($this->whenLoaded('branches')),
            'services'       => PublicServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
