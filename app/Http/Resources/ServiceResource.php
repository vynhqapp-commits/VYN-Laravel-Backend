<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'price'            => $this->price,
            'deposit_amount'   => $this->deposit_amount,
            'cost'             => $this->cost,
            'is_active'        => $this->is_active,
            'category'         => new ServiceCategoryResource($this->whenLoaded('category')),
            'pricing_tiers'    => $this->whenLoaded('pricingTiers', fn () =>
                $this->pricingTiers->map(fn ($t) => [
                    'id'         => $t->id,
                    'tier_label' => $t->tier_label,
                    'price'      => $t->price,
                ])->values()
            ),
            'add_ons'          => $this->whenLoaded('addOns', fn () =>
                $this->addOns->map(fn ($a) => [
                    'id'               => $a->id,
                    'name'             => $a->name,
                    'description'      => $a->description,
                    'price'            => $a->price,
                    'duration_minutes' => $a->duration_minutes,
                    'is_active'        => (bool) $a->is_active,
                ])->values()
            ),
        ];
    }
}
