<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
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

            $q = Service::with('category');
            if ($status === 'active') {
                $q->where('is_active', true);
            } elseif ($status === 'inactive') {
                $q->where('is_active', false);
            } elseif (!$includeInactive) {
                // default behavior remains POS-safe
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
                'cost'                => 'nullable|numeric|min:0',
                'is_active'           => 'sometimes|boolean',
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
