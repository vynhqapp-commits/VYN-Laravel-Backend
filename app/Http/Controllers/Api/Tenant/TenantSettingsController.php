<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantSettingsController extends Controller
{
    /**
     * Current tenant (salon) settings for the authenticated salon_owner or manager.
     */
    public function index()
    {
        try {
            $user = auth('api')->user();
            if (! $user->tenant_id) {
                return $this->forbidden('No tenant associated with this account');
            }

            $tenant = Tenant::findOrFail($user->tenant_id);

            return $this->success(new TenantResource($tenant));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Update tenant-level settings (currency, timezone, VAT, policies, etc.).
     */
    public function update(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (! $user->tenant_id) {
                return $this->forbidden('No tenant associated with this account');
            }

            $tenant = Tenant::findOrFail($user->tenant_id);

            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:500',
                'timezone' => 'nullable|string|max:100',
                'currency' => 'nullable|string|size:3',
                'vat_rate' => 'nullable|numeric|min:0|max:100',
                'logo' => 'nullable|string|max:500',
                'gender_preference' => 'nullable|in:ladies,gents,unisex',
                'preferred_locale' => 'nullable|string|max:10',
                'cancellation_window_hours' => 'nullable|integer|min:0|max:168',
                'cancellation_policy_mode' => 'nullable|in:soft,hard,none',
            ]);

            $tenant->update($data);

            return $this->success(new TenantResource($tenant->fresh()), 'Settings updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
