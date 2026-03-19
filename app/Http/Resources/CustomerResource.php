<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'email'      => $this->email,
            'birthday'   => $this->birthday,
            'gender'     => $this->gender,
            'tags'       => $this->tags,
            'notes'      => CustomerNoteResource::collection($this->whenLoaded('notes')),
            'created_at' => $this->created_at,
        ];
    }
}
