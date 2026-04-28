<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\StoreMembershipPlanTemplateRequest;
use App\Http\Requests\Api\Tenant\StorePackageTemplateRequest;
use App\Http\Requests\Api\Tenant\UpdateMembershipPlanTemplateRequest;
use App\Http\Requests\Api\Tenant\UpdatePackageTemplateRequest;
use App\Models\MembershipPlanTemplate;
use App\Models\ServicePackageTemplate;
use App\Services\Catalog\PackageMembershipCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageMembershipCatalogController extends Controller
{
    public function __construct(
        private readonly PackageMembershipCatalogService $catalog,
    ) {}

    public function indexPackages(Request $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        $rows = $this->catalog->listPackages((int) $tenantId, $request->boolean('active_only'));

        return $this->success($rows);
    }

    public function storePackage(StorePackageTemplateRequest $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        $pkg = $this->catalog->createPackage((int) $tenantId, $request->validated());

        return $this->success($this->catalog->packageResource($pkg), 'Package template created', 201);
    }

    public function updatePackage(UpdatePackageTemplateRequest $request, int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $pkg = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $pkg = $this->catalog->updatePackage($pkg, $request->validated());

        return $this->success($this->catalog->packageResource($pkg), 'Package template updated');
    }

    public function destroyPackage(int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $pkg = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->catalog->deletePackage($pkg);

        return $this->success(null, 'Package template deleted');
    }

    public function indexMemberships(Request $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        $rows = $this->catalog->listMemberships((int) $tenantId, $request->boolean('active_only'));

        return $this->success($rows);
    }

    public function storeMembership(StoreMembershipPlanTemplateRequest $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        $plan = $this->catalog->createMembership((int) $tenantId, $request->validated());

        return $this->success($this->catalog->membershipResource($plan), 'Membership plan created', 201);
    }

    public function updateMembership(UpdateMembershipPlanTemplateRequest $request, int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $plan = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $plan = $this->catalog->updateMembership($plan, $request->validated());

        return $this->success($this->catalog->membershipResource($plan), 'Membership plan updated');
    }

    public function destroyMembership(int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $plan = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->catalog->deleteMembership($plan);

        return $this->success(null, 'Membership plan deleted');
    }
}
