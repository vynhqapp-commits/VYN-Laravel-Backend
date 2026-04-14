<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceAddOn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceAddOnController extends Controller
{
    public function index(Service $service): JsonResponse
    {
        $addOns = $service->addOns()->orderBy('name')->get();

        return $this->success($addOns->map(fn (ServiceAddOn $a) => $this->resource($a))->values());
    }

    public function store(Request $request, Service $service): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'             => 'required|string|max:255',
                'description'      => 'nullable|string|max:1000',
                'price'            => 'required|numeric|min:0',
                'duration_minutes' => 'nullable|integer|min:0',
                'is_active'        => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $data['tenant_id'] = $service->tenant_id;
        $addOn = $service->addOns()->create($data);

        return $this->success($this->resource($addOn), 'Add-on created', 201);
    }

    public function update(Request $request, Service $service, ServiceAddOn $addOn): JsonResponse
    {
        if ((int) $addOn->service_id !== (int) $service->id) {
            return $this->notFound('Add-on not found');
        }

        try {
            $data = $request->validate([
                'name'             => 'sometimes|string|max:255',
                'description'      => 'nullable|string|max:1000',
                'price'            => 'sometimes|numeric|min:0',
                'duration_minutes' => 'nullable|integer|min:0',
                'is_active'        => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $addOn->update($data);

        return $this->success($this->resource($addOn), 'Add-on updated');
    }

    public function destroy(Service $service, ServiceAddOn $addOn): JsonResponse
    {
        if ((int) $addOn->service_id !== (int) $service->id) {
            return $this->notFound('Add-on not found');
        }

        $addOn->delete();

        return $this->success(null, 'Add-on deleted');
    }

    private function resource(ServiceAddOn $a): array
    {
        return [
            'id'               => (string) $a->id,
            'name'             => $a->name,
            'description'      => $a->description,
            'price'            => $a->price,
            'duration_minutes' => (int) $a->duration_minutes,
            'is_active'        => (bool) $a->is_active,
            'created_at'       => $a->created_at?->toIso8601String(),
        ];
    }
}
