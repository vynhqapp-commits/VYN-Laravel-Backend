<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->branch_id,
            'product_id' => $this->product_id,
            'quantity' => (int) $this->quantity,
            'low_stock_threshold' => $this->low_stock_threshold !== null ? (int) $this->low_stock_threshold : null,
            'Product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'tenant_id' => $this->product->tenant_id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'cost' => (string) $this->product->cost,
                'price' => (string) $this->product->price,
                'is_active' => (bool) $this->product->is_active,
            ] : null),
            'Location' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'tenant_id' => $this->branch->tenant_id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
                'timezone' => $this->branch->timezone,
                'status' => $this->branch->is_active ? 'active' : 'inactive',
            ] : null),
        ];
    }
}
