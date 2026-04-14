<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomerMembership extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'name',
        'plan',
        'start_date',
        'renewal_date',
        'interval_months',
        'service_credits_per_renewal',
        'remaining_services',
        'status',
        'auto_renew',
    ];

    protected $casts = [
        'start_date' => 'date',
        'renewal_date' => 'date',
        'interval_months' => 'integer',
        'service_credits_per_renewal' => 'integer',
        'remaining_services' => 'integer',
        'auto_renew' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function packages()
    {
        return $this->hasMany(CustomerServicePackage::class, 'membership_id');
    }
}

