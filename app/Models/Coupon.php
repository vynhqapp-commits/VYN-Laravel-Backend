<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'type',
        'value',
        'is_active',
        'starts_at',
        'ends_at',
        'usage_limit',
        'used_count',
        'min_subtotal',
        'name',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
    ];
}

