<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => (string) $this->value,
            'is_active' => (bool) $this->is_active,
            'starts_at' => optional($this->starts_at)->toISOString(),
            'ends_at' => optional($this->ends_at)->toISOString(),
            'usage_limit' => $this->usage_limit,
            'used_count' => (int) ($this->used_count ?? 0),
            'min_subtotal' => $this->min_subtotal !== null ? (string) $this->min_subtotal : null,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
