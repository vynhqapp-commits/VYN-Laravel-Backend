<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceCategoryController extends Controller
{
    public function index()
    {
        try {
            $categories = ServiceCategory::with('services')->get();
            return $this->success(ServiceCategoryResource::collection($categories));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate(['name' => 'required|string|max:255']);
            return $this->created(new ServiceCategoryResource(ServiceCategory::create($data)));
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, ServiceCategory $serviceCategory)
    {
        try {
            $serviceCategory->update($request->validate(['name' => 'required|string|max:255']));
            return $this->success(new ServiceCategoryResource($serviceCategory), 'Category updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(ServiceCategory $serviceCategory)
    {
        try {
            $serviceCategory->delete();
            return $this->success(null, 'Category deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
