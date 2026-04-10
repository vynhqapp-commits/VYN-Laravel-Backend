<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlanTemplate;
use App\Models\ServicePackageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PackageMembershipCatalogController extends Controller
{
    /* ── Package Templates ─────────────────────────────────────────────── */

    public function indexPackages(Request $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $query = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return $this->success($query->get()->map(fn ($p) => $this->packageResource($p)));
    }

    public function storePackage(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'           => 'required|string|max:255',
                'description'    => 'nullable|string|max:1000',
                'price'          => 'required|numeric|min:0',
                'total_sessions' => 'required|integer|min:1',
                'validity_days'  => 'nullable|integer|min:1',
                'is_active'      => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $data['tenant_id'] = $tenantId;
        $pkg = ServicePackageTemplate::create($data);

        return $this->success($this->packageResource($pkg), 'Package template created', 201);
    }

    public function updatePackage(Request $request, int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $pkg = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        try {
            $data = $request->validate([
                'name'           => 'sometimes|string|max:255',
                'description'    => 'nullable|string|max:1000',
                'price'          => 'sometimes|numeric|min:0',
                'total_sessions' => 'sometimes|integer|min:1',
                'validity_days'  => 'nullable|integer|min:1',
                'is_active'      => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $pkg->update($data);

        return $this->success($this->packageResource($pkg), 'Package template updated');
    }

    public function destroyPackage(int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $pkg = ServicePackageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $pkg->delete();

        return $this->success(null, 'Package template deleted');
    }

    /* ── Membership Plan Templates ─────────────────────────────────────── */

    public function indexMemberships(Request $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $query = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return $this->success($query->get()->map(fn ($m) => $this->membershipResource($m)));
    }

    public function storeMembership(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string|max:1000',
                'price'               => 'required|numeric|min:0',
                'interval_months'     => 'required|integer|min:1',
                'credits_per_renewal' => 'required|integer|min:0',
                'is_active'           => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $data['tenant_id'] = $tenantId;
        $plan = MembershipPlanTemplate::create($data);

        return $this->success($this->membershipResource($plan), 'Membership plan created', 201);
    }

    public function updateMembership(Request $request, int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $plan = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        try {
            $data = $request->validate([
                'name'                => 'sometimes|string|max:255',
                'description'         => 'nullable|string|max:1000',
                'price'               => 'sometimes|numeric|min:0',
                'interval_months'     => 'sometimes|integer|min:1',
                'credits_per_renewal' => 'sometimes|integer|min:0',
                'is_active'           => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $plan->update($data);

        return $this->success($this->membershipResource($plan), 'Membership plan updated');
    }

    public function destroyMembership(int $id): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $plan = MembershipPlanTemplate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $plan->delete();

        return $this->success(null, 'Membership plan deleted');
    }

    /* ── Private Resource Helpers ──────────────────────────────────────── */

    private function packageResource(ServicePackageTemplate $p): array
    {
        return [
            'id'             => (string) $p->id,
            'name'           => $p->name,
            'description'    => $p->description,
            'price'          => $p->price,
            'total_sessions' => (int) $p->total_sessions,
            'validity_days'  => $p->validity_days ? (int) $p->validity_days : null,
            'is_active'      => (bool) $p->is_active,
            'created_at'     => $p->created_at?->toIso8601String(),
        ];
    }

    private function membershipResource(MembershipPlanTemplate $m): array
    {
        return [
            'id'                  => (string) $m->id,
            'name'                => $m->name,
            'description'         => $m->description,
            'price'               => $m->price,
            'interval_months'     => (int) $m->interval_months,
            'credits_per_renewal' => (int) $m->credits_per_renewal,
            'is_active'           => (bool) $m->is_active,
            'created_at'          => $m->created_at?->toIso8601String(),
        ];
    }
}
