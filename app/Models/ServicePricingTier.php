<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServicePricingTier extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'tier_label',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
