<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'q' => 'nullable|string|max:64',
                'is_active' => 'nullable|boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $perPage = (int) ($data['per_page'] ?? 50);
            $qTerm = trim((string) ($data['q'] ?? ''));

            $q = Coupon::query()->orderByDesc('id');
            if (array_key_exists('is_active', $data)) {
                $q->where('is_active', (bool) $data['is_active']);
            }
            if ($qTerm !== '') {
                $needle = '%' . strtolower($qTerm) . '%';
                $q->where(function ($sub) use ($needle) {
                    $sub->whereRaw('LOWER(code) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
                });
            }

            $rows = $q->paginate($perPage)->appends($request->query());

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => CouponResource::collection($rows->items()),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'per_page' => $rows->perPage(),
                    'total' => $rows->total(),
                    'last_page' => $rows->lastPage(),
                    'from' => $rows->firstItem(),
                    'to' => $rows->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'code' => 'required|string|max:64',
                'type' => 'required|in:flat,percent',
                'value' => 'required|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
                'usage_limit' => 'nullable|integer|min:1',
                'min_subtotal' => 'nullable|numeric|min:0',
                'name' => 'nullable|string|max:120',
                'description' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);

            $code = strtoupper(trim((string) $data['code']));
            $exists = Coupon::query()->where('code', $code)->exists();
            if ($exists) {
                return $this->validationError(['code' => ['Coupon code already exists.']]);
            }

            $row = Coupon::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'type' => $data['type'],
                'value' => $data['value'],
                'is_active' => $data['is_active'] ?? true,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'usage_limit' => $data['usage_limit'] ?? null,
                'used_count' => 0,
                'min_subtotal' => $data['min_subtotal'] ?? null,
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            return $this->created(new CouponResource($row));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Coupon $coupon): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $coupon->tenant_id !== (int) $tenantId) return $this->notFound('Coupon not found');
            return $this->success(new CouponResource($coupon));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        try {
            $data = $request->validate([
                'code' => 'sometimes|string|max:64',
                'type' => 'sometimes|in:flat,percent',
                'value' => 'sometimes|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
                'usage_limit' => 'nullable|integer|min:1',
                'min_subtotal' => 'nullable|numeric|min:0',
                'name' => 'nullable|string|max:120',
                'description' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $coupon->tenant_id !== (int) $tenantId) return $this->notFound('Coupon not found');

            if (array_key_exists('code', $data)) {
                $code = strtoupper(trim((string) $data['code']));
                $exists = Coupon::query()
                    ->where('code', $code)
                    ->where('id', '!=', $coupon->id)
                    ->exists();
                if ($exists) return $this->validationError(['code' => ['Coupon code already exists.']]);
                $data['code'] = $code;
            }

            $coupon->update($data);
            return $this->success(new CouponResource($coupon), 'Coupon updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if (!$tenantId) return $this->error('Tenant required', 422);
            if ((int) $coupon->tenant_id !== (int) $tenantId) return $this->notFound('Coupon not found');
            $coupon->delete();
            return $this->success(null, 'Coupon deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

