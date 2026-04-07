<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'branch_id' => (string) $this->branch_id,
            'staff_id' => $this->staff_id ? (string) $this->staff_id : null,
            'starts_at' => optional($this->starts_at)->toISOString(),
            'ends_at' => optional($this->ends_at)->toISOString(),
            'reason' => $this->reason,
            'created_by' => $this->created_by ? (string) $this->created_by : null,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}

