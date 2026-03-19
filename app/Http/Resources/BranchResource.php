<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'address'    => $this->address,
            'timezone'   => $this->timezone,
            'is_active'  => $this->is_active,
            'staff'      => StaffResource::collection($this->whenLoaded('staff')),
            'created_at' => $this->created_at,
        ];
    }
}
