<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MembershipPlanTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'price',
        'interval_months',
        'credits_per_renewal',
        'is_active',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'interval_months'     => 'integer',
        'credits_per_renewal' => 'integer',
        'is_active'           => 'boolean',
    ];
}
