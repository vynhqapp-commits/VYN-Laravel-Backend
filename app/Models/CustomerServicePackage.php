<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomerServicePackage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'membership_id',
        'name',
        'total_services',
        'remaining_services',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'total_services' => 'integer',
        'remaining_services' => 'integer',
        'expires_at' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function membership()
    {
        return $this->belongsTo(CustomerMembership::class, 'membership_id');
    }
}

