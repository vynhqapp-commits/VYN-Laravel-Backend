<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceProductUsage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'product_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function service() { return $this->belongsTo(Service::class); }
    public function product() { return $this->belongsTo(Product::class); }
}

