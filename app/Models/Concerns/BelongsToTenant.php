<?php

namespace App\Models\Concerns;

use Spatie\Multitenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (Tenant::checkCurrent()) {
                $query->where('tenant_id', Tenant::current()->id);
            }
        });

        static::creating(function ($model) {
            if (Tenant::checkCurrent()) {
                $model->tenant_id ??= Tenant::current()->id;
            }
        });
    }
}
