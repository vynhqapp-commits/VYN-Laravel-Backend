<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'staff_id', 'service_id', 'type', 'value', 'tier_threshold', 'is_active'];

    protected $casts = ['value' => 'decimal:2', 'tier_threshold' => 'decimal:2', 'is_active' => 'boolean'];

    public function staff() { return $this->belongsTo(Staff::class); }
    public function service() { return $this->belongsTo(Service::class); }
}
