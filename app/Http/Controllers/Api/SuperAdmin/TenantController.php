<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SuperAdmin\StoreTenantRequest;
use App\Http\Requests\Api\SuperAdmin\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\AuditLogger;

class TenantController extends Controller
{
    public function index()
    {
        try {
            $tenants = Tenant::latest()->paginate(20);
            return $this->paginated(TenantResource::collection($tenants)->resource, 'Tenants retrieved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(StoreTenantRequest $request)
    {
        try {
            $data = $request->validated();

            $tenant = Tenant::create($data);
            AuditLogger::log(optional($request->user('api'))->id, (int) $tenant->id, 'admin.tenant.create', [
                'name' => $tenant->name,
            ]);
            return $this->created(new TenantResource($tenant));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Tenant $tenant)
    {
        try {
            return $this->success(new TenantResource($tenant));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        try {
            $data = $request->validated();

            $tenant->update($data);
            AuditLogger::log(optional($request->user('api'))->id, (int) $tenant->id, 'admin.tenant.update', [
                'fields' => array_keys($data),
            ]);

            return $this->success(new TenantResource($tenant), 'Tenant updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function suspend(Tenant $tenant)
    {
        try {
            $tenant->update(['subscription_status' => 'suspended']);
            AuditLogger::log(optional(request()->user('api'))->id, (int) $tenant->id, 'admin.tenant.suspend');
            return $this->success(new TenantResource($tenant), 'Tenant suspended');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function activate(Tenant $tenant)
    {
        try {
            $tenant->update(['subscription_status' => 'active']);
            AuditLogger::log(optional(request()->user('api'))->id, (int) $tenant->id, 'admin.tenant.activate');
            return $this->success(new TenantResource($tenant), 'Tenant activated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Tenant $tenant)
    {
        try {
            AuditLogger::log(optional(request()->user('api'))->id, (int) $tenant->id, 'admin.tenant.delete', [
                'name' => $tenant->name,
            ]);
            $tenant->delete();
            return $this->success(null, 'Tenant deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
