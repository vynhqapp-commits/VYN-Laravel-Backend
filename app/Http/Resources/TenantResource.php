<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'slug'                => $this->slug,
            'domain'              => $this->domain,
            'plan'                => $this->plan,
            'subscription_status' => $this->subscription_status,
            'timezone'            => $this->timezone,
            'currency'            => $this->currency,
            'phone'               => $this->phone,
            'address'             => $this->address,
            'logo'                => $this->logo,
            'gender_preference'   => $this->gender_preference,
            'cancellation_window_hours' => (int) ($this->cancellation_window_hours ?? 24),
            'cancellation_policy_mode'  => $this->cancellation_policy_mode ?? 'soft',
            'created_at'          => $this->created_at,
        ];
    }
}
