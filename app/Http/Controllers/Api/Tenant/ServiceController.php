<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServicePricingTier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    private array $eagerLoads = ['category', 'pricingTiers', 'addOns'];

    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'include_inactive' => 'nullable|boolean',
                'q' => 'nullable|string|max:255',
                'status' => 'nullable|in:all,active,inactive',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
            $includeInactive = filter_var($data['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $status = $data['status'] ?? null;
            $qTerm = trim((string) ($data['q'] ?? ''));
            $perPage = (int) ($data['per_page'] ?? 20);

            $q = Service::with($this->eagerLoads);
            if ($status === 'active') {
                $q->where('is_active', true);
            } elseif ($status === 'inactive') {
                $q->where('is_active', false);
            } elseif (!$includeInactive) {
                $q->where('is_active', true);
            }

            if ($qTerm !== '') {
                $q->where(function ($sub) use ($qTerm) {
                    $sub->where('name', 'like', "%{$qTerm}%")
                        ->orWhere('description', 'like', "%{$qTerm}%");
                });
            }

            $services = $q->orderBy('name')->paginate($perPage)->appends($request->query());

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => ServiceResource::collection($services->items()),
                'meta' => [
                    'current_page' => $services->currentPage(),
                    'per_page' => $services->perPage(),
                    'total' => $services->total(),
                    'last_page' => $services->lastPage(),
                    'from' => $services->firstItem(),
                    'to' => $services->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'service_category_id' => 'nullable|exists:service_categories,id',
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string',
                'duration_minutes'    => 'required|integer|min:1',
                'price'               => 'required|numeric|min:0',
                'deposit_amount'      => 'nullable|numeric|min:0',
                'cost'                => 'nullable|numeric|min:0',
                'is_active'           => 'sometimes|boolean',
                'pricing_tiers'              => 'nullable|array',
                'pricing_tiers.*.tier_label' => 'required_with:pricing_tiers|string|max:64',
                'pricing_tiers.*.price'      => 'required_with:pricing_tiers|numeric|min:0',
            ]);

            $tiers = $data['pricing_tiers'] ?? [];
            unset($data['pricing_tiers']);

            $service = Service::create($data);
            $this->syncTiers($service, $tiers);
            $service->load($this->eagerLoads);

            return $this->created(new ServiceResource($service));

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Service $service)
    {
        try {
            return $this->success(new ServiceResource($service->load($this->eagerLoads)));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Service $service)
    {
        try {
            $data = $request->validate([
                'service_category_id' => 'nullable|exists:service_categories,id',
                'name'                => 'sometimes|string|max:255',
                'description'         => 'nullable|string',
                'duration_minutes'    => 'sometimes|integer|min:1',
                'price'               => 'sometimes|numeric|min:0',
                'deposit_amount'      => 'nullable|numeric|min:0',
                'cost'                => 'nullable|numeric|min:0',
                'is_active'           => 'sometimes|boolean',
                'pricing_tiers'              => 'nullable|array',
                'pricing_tiers.*.tier_label' => 'required_with:pricing_tiers|string|max:64',
                'pricing_tiers.*.price'      => 'required_with:pricing_tiers|numeric|min:0',
            ]);

            $tiers = $data['pricing_tiers'] ?? null;
            unset($data['pricing_tiers']);

            $service->update($data);

            if ($tiers !== null) {
                $this->syncTiers($service, $tiers);
            }

            return $this->success(new ServiceResource($service->load($this->eagerLoads)), 'Service updated');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Service $service)
    {
        try {
            $service->delete();
            return $this->success(null, 'Service deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function syncTiers(Service $service, array $tiers): void
    {
        $service->pricingTiers()->delete();

        foreach ($tiers as $tier) {
            $service->pricingTiers()->create([
                'tenant_id'  => $service->tenant_id,
                'tier_label' => $tier['tier_label'],
                'price'      => $tier['price'],
            ]);
        }
    }
}
