<?php

namespace App\Models;

use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use App\Models\Branch;
use App\Models\Service;

class Tenant extends BaseTenant
{
    protected $fillable = [
        'name', 'slug', 'domain', 'plan', 'subscription_status',
        'timezone', 'currency', 'vat_rate', 'phone', 'address', 'logo',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            $tenant->slug ??= Str::slug($tenant->name);
        });
    }

    public function branches() { return $this->hasMany(Branch::class); }
    public function services() { return $this->hasMany(Service::class); }
}
