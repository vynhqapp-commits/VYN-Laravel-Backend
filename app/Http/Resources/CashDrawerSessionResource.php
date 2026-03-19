<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashDrawerSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'cash_drawer_id' => (string) $this->cash_drawer_id,
            'location_id' => $this->relationLoaded('cashDrawer') && $this->cashDrawer ? (string) $this->cashDrawer->branch_id : null,
            'status' => (string) $this->status,
            'opening_balance' => (string) $this->opening_balance,
            'closing_balance' => $this->closing_balance !== null ? (string) $this->closing_balance : null,
            'expected_balance' => $this->expected_balance !== null ? (string) $this->expected_balance : null,
            'discrepancy' => $this->discrepancy !== null ? (string) $this->discrepancy : null,
            'approval_required' => (bool) ($this->approval_required ?? false),
            'approved_by' => $this->approved_by ? (string) $this->approved_by : null,
            'approved_at' => optional($this->approved_at)->toISOString(),
            'approval_notes' => $this->approval_notes,
            'opened_at' => optional($this->opened_at)->toISOString(),
            'closed_at' => optional($this->closed_at)->toISOString(),
            // Frontend expects CashMovements
            'CashMovements' => $this->whenLoaded('movements', fn () => CashMovementResource::collection($this->movements)),
        ];
    }
}

