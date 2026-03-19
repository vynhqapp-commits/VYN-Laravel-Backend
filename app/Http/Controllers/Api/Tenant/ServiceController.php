<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index()
    {
        try {
            $services = Service::with('category')->where('is_active', true)->get();
            return $this->success(ServiceResource::collection($services));
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
                'cost'                => 'nullable|numeric|min:0',
            ]);

            $service = Service::create($data);
            $service->load('category');

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
            return $this->success(new ServiceResource($service->load('category')));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Service $service)
    {
        try {
            $service->update($request->validate([
                'service_category_id' => 'nullable|exists:service_categories,id',
                'name'                => 'sometimes|string|max:255',
                'description'         => 'nullable|string',
                'duration_minutes'    => 'sometimes|integer|min:1',
                'price'               => 'sometimes|numeric|min:0',
                'cost'                => 'nullable|numeric|min:0',
                'is_active'           => 'sometimes|boolean',
            ]));

            return $this->success(new ServiceResource($service->load('category')), 'Service updated');

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
}
