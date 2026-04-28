<?php

namespace App\Services\Catalog;

use App\Models\MembershipPlanTemplate;
use App\Models\ServicePackageTemplate;
use Illuminate\Support\Collection;

class PackageMembershipCatalogService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listPackages(int $tenantId, bool $activeOnly): Collection
    {
        $query = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->map(fn ($p) => $this->packageResource($p));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPackage(int $tenantId, array $data): ServicePackageTemplate
    {
        $data['tenant_id'] = $tenantId;

        return ServicePackageTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePackage(ServicePackageTemplate $pkg, array $data): ServicePackageTemplate
    {
        $pkg->update($data);

        return $pkg->fresh();
    }

    public function deletePackage(ServicePackageTemplate $pkg): void
    {
        $pkg->delete();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listMemberships(int $tenantId, bool $activeOnly): Collection
    {
        $query = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->map(fn ($m) => $this->membershipResource($m));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMembership(int $tenantId, array $data): MembershipPlanTemplate
    {
        $data['tenant_id'] = $tenantId;

        return MembershipPlanTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMembership(MembershipPlanTemplate $plan, array $data): MembershipPlanTemplate
    {
        $plan->update($data);

        return $plan->fresh();
    }

    public function deleteMembership(MembershipPlanTemplate $plan): void
    {
        $plan->delete();
    }

    public function packageResource(ServicePackageTemplate $p): array
    {
        return [
            'id' => (string) $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price' => $p->price,
            'total_sessions' => (int) $p->total_sessions,
            'validity_days' => $p->validity_days ? (int) $p->validity_days : null,
            'is_active' => (bool) $p->is_active,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    public function membershipResource(MembershipPlanTemplate $m): array
    {
        return [
            'id' => (string) $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'price' => $m->price,
            'interval_months' => (int) $m->interval_months,
            'credits_per_renewal' => (int) $m->credits_per_renewal,
            'is_active' => (bool) $m->is_active,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
