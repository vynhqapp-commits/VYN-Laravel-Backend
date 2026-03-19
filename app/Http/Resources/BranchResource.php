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
            'tenant_id'  => $this->tenant_id,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'contact_email' => $this->contact_email,
            'address'    => $this->address,
            'timezone'   => $this->timezone,
            'working_hours' => $this->working_hours,
            'is_active'  => $this->is_active,
            'status'     => $this->is_active ? 'active' : 'inactive',
            'staff'      => StaffResource::collection($this->whenLoaded('staff')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
