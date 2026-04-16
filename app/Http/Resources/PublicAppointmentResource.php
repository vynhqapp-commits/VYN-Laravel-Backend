<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'status' => $this->status,
            'service' => $this->whenLoaded(
                'services',
                fn() => $this->services->first()?->service ? [
                    'name' => $this->services->first()->service->name,
                    'duration_minutes' => $this->services->first()->service->duration_minutes,
                    'price' => $this->services->first()->service->price,
                ] : null
            ),
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ]),
            'staff' => $this->whenLoaded('staff', fn() => [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ]),
            'customer' => $this->whenLoaded('customer', fn() => [
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
        ];
    }
}
