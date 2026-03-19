<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'cost' => (string) $this->cost,
            'price' => (string) $this->price,
            'stock_quantity' => (int) $this->stock_quantity,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}

