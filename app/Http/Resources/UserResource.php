<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'tenantId'  => $this->tenant_id,
            'role'      => $this->whenLoaded('roles', fn() => $this->roles->first()?->name),
            'roles'     => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),
            'created_at'=> $this->created_at,
        ];
    }
}
