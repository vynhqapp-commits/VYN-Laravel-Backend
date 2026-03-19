<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceBranchAvailabilityOverride extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'branch_id',
        'date',
        'start_time',
        'end_time',
        'slot_minutes',
        'is_closed',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'slot_minutes' => 'integer',
        'is_closed' => 'boolean',
    ];

    public function service() { return $this->belongsTo(Service::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}

