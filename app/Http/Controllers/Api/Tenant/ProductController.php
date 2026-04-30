<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\ApprovalRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'is_active' => 'nullable|boolean',
                'search' => 'nullable|string|max:100',
                'category' => 'nullable|string|max:120',
                'classification' => 'nullable|in:retail,professional,both',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = Product::query()->latest('id');
            if (array_key_exists('is_active', $data)) {
                $q->where('is_active', (bool) $data['is_active']);
            }
            if (! empty($data['search'])) {
                $s = trim((string) $data['search']);
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                        ->orWhere('sku', 'like', "%{$s}%")
                        ->orWhere('description', 'like', "%{$s}%")
                        ->orWhere('category', 'like', "%{$s}%");
                });
            }
            if (!empty($data['category'])) {
                $q->where('category', $data['category']);
            }
            if (!empty($data['classification'])) {
                $q->where('classification', $data['classification']);
            }

            return $this->paginated($q->paginate((int) ($data['per_page'] ?? 20)));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:4000',
                'category' => 'nullable|string|max:120',
                'classification' => 'nullable|in:retail,professional,both',
                'sku' => 'nullable|string|max:80',
                'cost' => 'nullable|numeric|min:0',
                'price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'low_stock_threshold' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $product = Product::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'classification' => $data['classification'] ?? null,
                'sku' => $data['sku'] ?? null,
                'cost' => $data['cost'] ?? 0,
                'price' => $data['price'] ?? ($data['cost'] ?? 0),
                'stock_quantity' => $data['stock_quantity'] ?? 0,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->created(new ProductResource($product), 'Product created');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Product $product): JsonResponse
    {
        try {
            return $this->success(new ProductResource($product));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string|max:4000',
                'category' => 'nullable|string|max:120',
                'classification' => 'nullable|in:retail,professional,both',
                'sku' => 'nullable|string|max:80',
                'cost' => 'nullable|numeric|min:0',
                'price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'low_stock_threshold' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $product->update($data);
            return $this->success(new ProductResource($product), 'Product updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['receptionist', 'staff'])) {
                $existing = ApprovalRequest::query()
                    ->where('entity_type', 'product')
                    ->where('entity_id', (int) $product->id)
                    ->where('requested_action', 'delete')
                    ->where('status', ApprovalRequest::STATUS_PENDING)
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return $this->success($existing, 'Deletion request already pending', 202);
                }

                $req = ApprovalRequest::create([
                    'tenant_id' => (int) ($user->tenant_id ?? 0),
                    'branch_id' => null,
                    'entity_type' => 'product',
                    'entity_id' => (int) $product->id,
                    'requested_action' => 'delete',
                    'requested_by' => (int) $user->id,
                    'payload' => null,
                    'status' => ApprovalRequest::STATUS_PENDING,
                    'expires_at' => now()->addDays(7),
                ]);

                return $this->success($req, 'Deletion request submitted for approval', 202);
            }

            $product->delete();
            return $this->success(null, 'Product deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

