<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (string) $this->amount,
            'expense_date' => optional($this->expense_date)->toDateString(),
            'payment_method' => $this->payment_method,
            'Branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}
