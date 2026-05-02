<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_drawer_session_id' => $this->cash_drawer_session_id,
            // Frontend expects "in|out"
            'type' => $this->type === 'cash_out' ? 'out' : 'in',
            'amount' => (string) $this->amount,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}
