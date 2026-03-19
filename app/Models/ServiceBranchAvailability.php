<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceBranchAvailability extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'branch_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_minutes',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'slot_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function service() { return $this->belongsTo(Service::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}

