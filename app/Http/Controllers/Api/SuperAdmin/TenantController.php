<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name'     => 'required|string|max:255',
                'domain'   => 'nullable|string|unique:tenants',
                'plan'     => 'nullable|in:basic,pro,enterprise',
                'timezone' => 'nullable|string',
                'currency' => 'nullable|string|size:3',
                'phone'    => 'nullable|string',
                'address'  => 'nullable|string',
            ]);

            $tenant = Tenant::create($data);
            AuditLogger::log(optional($request->user('api'))->id, (int) $tenant->id, 'admin.tenant.create', [
                'name' => $tenant->name,
            ]);
            return $this->created(new TenantResource($tenant));

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
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

    public function update(Request $request, Tenant $tenant)
    {
        try {
            $data = $request->validate([
                'name'     => 'sometimes|string|max:255',
                'domain'   => 'sometimes|string|unique:tenants,domain,' . $tenant->id,
                'plan'     => 'sometimes|in:basic,pro,enterprise',
                'timezone' => 'sometimes|string',
                'currency' => 'sometimes|string|size:3',
                'phone'    => 'nullable|string',
                'address'  => 'nullable|string',
            ]);

            $tenant->update($data);
            AuditLogger::log(optional($request->user('api'))->id, (int) $tenant->id, 'admin.tenant.update', [
                'fields' => array_keys($data),
            ]);

            return $this->success(new TenantResource($tenant), 'Tenant updated');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
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
