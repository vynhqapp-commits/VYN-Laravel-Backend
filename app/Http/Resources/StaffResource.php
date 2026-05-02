<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialization' => $this->specialization,
            'photo_url' => $this->photo_url,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'schedules' => StaffScheduleResource::collection($this->whenLoaded('schedules')),
            'user' => new UserResource($this->whenLoaded('user')),
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values()
            ),
        ];
    }
}
