<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use App\Models\Branch;
use App\Models\SalonPhoto;
use App\Models\Service;
use App\Models\Review;

class Tenant extends BaseTenant
{
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'subscription_status',
        'timezone',
        'currency',
        'vat_rate',
        'phone',
        'address',
        'logo',
        'preferred_locale',
        'gender_preference',
        'average_rating',
        'cancellation_window_hours',
        'cancellation_policy_mode',
    ];

    protected $casts = [
        'cancellation_window_hours' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            $tenant->slug ??= Str::slug($tenant->name);
        });
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
    public function services()
    {
        return $this->hasMany(Service::class);
    }
    public function photos()
    {
        return $this->hasMany(SalonPhoto::class, 'salon_id')->orderBy('sort_order');
    }
    public function reviews()
    {
        return $this->hasMany(Review::class, 'salon_id');
    }

    public function approvedReviews()
    {
        return $this->hasMany(Review::class, 'salon_id')->where('status', 'approved');
    }

    public function scopePriceRange(Builder $query, float|int|null $min, float|int|null $max): Builder
    {
        if ($min === null && $max === null) {
            return $query;
        }

        return $query->whereHas('services', function (Builder $q) use ($min, $max) {
            $q->where('is_active', true);
            if ($min !== null) {
                $q->where('price', '>=', $min);
            }
            if ($max !== null) {
                $q->where('price', '<=', $max);
            }
        });
    }

    public function scopeMinRating(Builder $query, float|int|null $min): Builder
    {
        if ($min === null) {
            return $query;
        }

        return $query->whereNotNull('average_rating')->where('average_rating', '>=', $min);
    }

    public function scopeGenderPreference(Builder $query, ?string $preference): Builder
    {
        $preference = $preference ? trim($preference) : null;
        if (!$preference) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($preference) {
            $q->where('gender_preference', $preference)
                ->orWhereHas('branches', fn (Builder $b) => $b->where('gender_preference', $preference));
        });
    }

    public function scopeAvailableOn(Builder $query, ?string $dateYmd): Builder
    {
        $dateYmd = $dateYmd ? trim($dateYmd) : null;
        if (!$dateYmd) {
            return $query;
        }

        // Listing filter: "has any availability window that day"
        // Avoid generating slots here; just check weekly availability + overrides.
        $dayOfWeek = CarbonImmutable::createFromFormat('Y-m-d', $dateYmd)->dayOfWeek;

        return $query->where(function (Builder $q) use ($dateYmd, $dayOfWeek) {
            // Has any non-closed override window on that date, on an active branch
            $q->whereExists(function ($sub) use ($dateYmd) {
                $sub->selectRaw('1')
                    ->from('service_branch_availability_overrides as sbao')
                    ->join('branches as b', 'b.id', '=', 'sbao.branch_id')
                    ->whereColumn('sbao.tenant_id', 'tenants.id')
                    ->whereColumn('b.tenant_id', 'tenants.id')
                    ->where('b.is_active', true)
                    ->where('sbao.date', $dateYmd)
                    ->where('sbao.is_closed', false);
            })
            // Or has any active weekly window for that day, on an active branch
            ->orWhereExists(function ($sub) use ($dayOfWeek) {
                $sub->selectRaw('1')
                    ->from('service_branch_availabilities as sba')
                    ->join('branches as b', 'b.id', '=', 'sba.branch_id')
                    ->whereColumn('sba.tenant_id', 'tenants.id')
                    ->whereColumn('b.tenant_id', 'tenants.id')
                    ->where('b.is_active', true)
                    ->where('sba.day_of_week', $dayOfWeek)
                    ->where('sba.is_active', true);
            })
            // Exclude tenants where there are overrides for that date and all are closed (no open override)
            ->whereNotExists(function ($sub) use ($dateYmd) {
                $sub->selectRaw('1')
                    ->from('service_branch_availability_overrides as sbao_all')
                    ->join('branches as b', 'b.id', '=', 'sbao_all.branch_id')
                    ->whereColumn('sbao_all.tenant_id', 'tenants.id')
                    ->whereColumn('b.tenant_id', 'tenants.id')
                    ->where('b.is_active', true)
                    ->where('sbao_all.date', $dateYmd)
                    ->whereNotExists(function ($sub2) use ($dateYmd) {
                        $sub2->selectRaw('1')
                            ->from('service_branch_availability_overrides as sbao_open')
                            ->join('branches as b2', 'b2.id', '=', 'sbao_open.branch_id')
                            ->whereColumn('sbao_open.tenant_id', 'tenants.id')
                            ->whereColumn('b2.tenant_id', 'tenants.id')
                            ->where('b2.is_active', true)
                            ->where('sbao_open.date', $dateYmd)
                            ->where('sbao_open.is_closed', false);
                    });
            });
        });
    }
}
