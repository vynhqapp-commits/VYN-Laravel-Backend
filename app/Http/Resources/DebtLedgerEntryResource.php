<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtLedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => (string) $this->type,
            'amount' => (string) $this->amount,
            'balance_after' => (string) $this->balance_after,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

