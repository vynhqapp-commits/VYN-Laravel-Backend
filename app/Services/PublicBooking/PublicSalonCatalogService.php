<?php

namespace App\Services\PublicBooking;

use App\Models\Branch;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PublicSalonCatalogService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function paginateSalonListing(array $validated): LengthAwarePaginator
    {
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = min((int) ($validated['per_page'] ?? 12), 50);

        $query = Tenant::withCount([
            'branches as branch_count' => fn ($q) => $q->where('is_active', true),
        ])
            ->where('subscription_status', '!=', 'suspended')
            ->orderBy('name');

        $this->applyPublicSalonListFilters($query, $validated);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $needle = '%' . mb_strtolower($search) . '%';
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(address) LIKE ?', [$needle])
                    ->orWhereHas('services', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$needle])->where('is_active', true))
                    ->orWhereHas('branches.staff', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$needle])->where('is_active', true));
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function paginateNearbySalonListing(array $validated): LengthAwarePaginator
    {
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $radiusKm = (float) ($validated['radius_km'] ?? 10);
        $perPage = min((int) ($validated['per_page'] ?? 24), 50);

        $driver = DB::connection()->getDriverName();
        $earthRadiusKm = 6371;
        $distanceSql = $driver === 'sqlite'
            ? '(111.045 * (abs(b.lat - ?) + abs(b.lng - ?)))'
            : "($earthRadiusKm * acos(cos(radians(?)) * cos(radians(b.lat)) * cos(radians(b.lng) - radians(?)) + sin(radians(?)) * sin(radians(b.lat))))";
        $bindings = $driver === 'sqlite'
            ? [$lat, $lng]
            : [$lat, $lng, $lat];

        $query = Tenant::query()
            ->select('tenants.*')
            ->selectRaw('MIN(' . $distanceSql . ') as distance_km', $bindings)
            ->join('branches as b', function ($join) {
                $join->on('b.tenant_id', '=', 'tenants.id')
                    ->where('b.is_active', true)
                    ->whereNotNull('b.lat')
                    ->whereNotNull('b.lng');
            })
            ->where('tenants.subscription_status', '!=', 'suspended')
            ->groupBy('tenants.id')
            ->havingRaw(
                'MIN(' . $distanceSql . ') <= ?',
                array_merge($bindings, [$radiusKm])
            )
            ->orderBy('distance_km');

        $this->applyPublicSalonListFilters($query, $validated);

        return $query->paginate($perPage);
    }

    /**
     * @return array{tenant: Tenant, branches: \Illuminate\Support\Collection, services: \Illuminate\Support\Collection}|null
     */
    public function findSalonDetailBySlug(string $slug): ?array
    {
        $tenant = Tenant::where('slug', $slug)
            ->where('subscription_status', '!=', 'suspended')
            ->with([
                'photos',
                'approvedReviews' => fn ($q) => $q
                    ->with('customer:id,full_name')
                    ->latest('id')
                    ->limit(20),
            ])
            ->first();

        if (! $tenant) {
            return null;
        }

        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        $services = Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->with('category')
            ->orderBy('name')
            ->get();

        return compact('tenant', 'branches', 'services');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Tenant>|\Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $validated
     */
    public function applyPublicSalonListFilters(mixed $query, array $validated): void
    {
        $query
            ->when(
                array_key_exists('price_min', $validated) || array_key_exists('price_max', $validated),
                fn ($q) => $q->priceRange($validated['price_min'] ?? null, $validated['price_max'] ?? null)
            )
            ->when(
                array_key_exists('rating_min', $validated),
                fn ($q) => $q->minRating($validated['rating_min'] ?? null)
            )
            ->when(
                array_key_exists('availability', $validated),
                fn ($q) => $q->availableOn($validated['availability'] ?? null)
            )
            ->when(
                array_key_exists('gender_preference', $validated),
                fn ($q) => $q->genderPreference($validated['gender_preference'] ?? null)
            );
    }
}
