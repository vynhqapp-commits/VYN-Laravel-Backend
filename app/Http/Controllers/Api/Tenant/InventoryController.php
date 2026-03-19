<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function byBranch(Branch $branch): JsonResponse
    {
        try {
            $rows = Inventory::query()
                ->where('branch_id', $branch->id)
                ->with(['product', 'branch'])
                ->orderByDesc('id')
                ->get();

            return $this->success(InventoryResource::collection($rows));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/inventory/stock
     * Adjust stock and write StockMovement.
     */
    public function adjust(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer', // delta, can be negative
                'reason' => 'nullable|string|max:80',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        $delta = (int) $data['quantity'];
        if ($delta === 0) return $this->error('Quantity change cannot be 0', 422);

        DB::beginTransaction();
        try {
            /** @var Inventory $inv */
            $inv = Inventory::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'branch_id' => (int) $data['branch_id'],
                'product_id' => (int) $data['product_id'],
            ], [
                'quantity' => 0,
            ]);

            $newQty = (int) $inv->quantity + $delta;
            if ($newQty < 0) {
                DB::rollBack();
                return $this->error('Insufficient stock for this adjustment', 422);
            }

            $inv->update(['quantity' => $newQty]);

            $type = $delta > 0 ? 'in' : 'out';
            StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => (int) $data['branch_id'],
                'product_id' => (int) $data['product_id'],
                'type' => $type,
                'quantity' => abs($delta),
                'reason' => $data['reason'] ?? 'adjustment',
            ]);

            AuditLogger::log($userId, $tenantId, 'inventory.adjusted', [
                'branch_id' => (int) $data['branch_id'],
                'product_id' => (int) $data['product_id'],
                'delta' => $delta,
                'new_quantity' => $newQty,
                'reason' => $data['reason'] ?? 'adjustment',
            ]);

            DB::commit();
            return $this->success(new InventoryResource($inv->load(['product', 'branch'])), 'Stock updated');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }
}

