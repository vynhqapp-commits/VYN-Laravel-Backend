<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServicePackageTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'price',
        'total_sessions',
        'validity_days',
        'is_active',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'total_sessions' => 'integer',
        'validity_days'  => 'integer',
        'is_active'      => 'boolean',
    ];
}
