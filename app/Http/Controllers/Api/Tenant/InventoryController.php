<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function byBranch(Branch $branch): JsonResponse
    {
        try {
            $tenantId = auth('api')->user()?->tenant_id;
            if ($tenantId === null || (int) $branch->tenant_id !== (int) $tenantId) {
                return $this->error('Forbidden', 403);
            }

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
     * GET /api/inventory/{branch}/movements
     */
    public function movements(Request $request, Branch $branch): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if ($tenantId === null || (int) $branch->tenant_id !== (int) $tenantId) {
            return $this->error('Forbidden', 403);
        }

        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                'product_id' => 'nullable|exists:products,id',
                'type' => ['nullable', 'string', Rule::in(StockMovement::TYPES)],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $movements = StockMovement::query()
            ->with(['product:id,name', 'branch:id,name'])
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branch->id)
            ->whereBetween('created_at', [$from, $to])
            ->when(!empty($data['product_id']), fn ($q) => $q->where('product_id', $data['product_id']))
            ->when(!empty($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->latest('id')
            ->limit(500)
            ->get();

        $summary = [
            'sold' => (int) $movements->where('type', 'sold')->sum('quantity'),
            'service_used' => (int) $movements->whereIn('type', ['service_deduction', 'service_usage'])->sum('quantity'),
            'adjustment_in' => (int) $movements->whereIn('type', ['in', 'return'])->sum('quantity'),
            'adjustment_out' => (int) $movements->whereIn('type', ['out', 'damage', 'theft', 'expired'])->sum('quantity'),
        ];

        $rows = $movements->map(function (StockMovement $m) {
            return [
                'id' => (string) $m->id,
                'branch_id' => $m->branch_id ? (string) $m->branch_id : null,
                'branch_name' => $m->branch?->name,
                'product_id' => $m->product_id ? (string) $m->product_id : null,
                'product_name' => $m->product?->name,
                'type' => (string) $m->type,
                'quantity' => (int) $m->quantity,
                'reason' => $m->reason,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id ? (string) $m->reference_id : null,
                'created_at' => optional($m->created_at)->toISOString(),
            ];
        })->values();

        return $this->success([
            'from' => $data['from'],
            'to' => $data['to'],
            'summary' => $summary,
            'rows' => $rows,
        ]);
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
                'quantity' => 'required|integer',
                'reason' => 'nullable|string|max:255',
                'type' => ['nullable', 'string', Rule::in(['adjustment', 'return', 'damage', 'theft', 'expired', 'transfer', 'service_usage'])],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $reasonType = $data['type'] ?? 'adjustment';

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        if ($reasonType !== 'adjustment' && $reason === '') {
            return $this->validationError(['reason' => ['Reason is required for this adjustment type.']]);
        }
        if ($reason !== '' && strlen($reason) < 3) {
            return $this->validationError(['reason' => ['Reason must be at least 3 characters.']]);
        }
        if ($reasonType === 'adjustment' && $reason === '') {
            $reason = 'adjustment';
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) {
            return $this->error('Tenant required', 422);
        }

        $branch = Branch::query()->where('id', $data['branch_id'])->first();
        if (!$branch || (int) $branch->tenant_id !== (int) $tenantId) {
            return $this->error('Invalid branch', 422);
        }

        $delta = (int) $data['quantity'];
        if ($delta === 0) {
            return $this->error('Quantity change cannot be 0', 422);
        }

        if ($reasonType === 'service_usage' && $delta >= 0) {
            return $this->error('Service usage must reduce stock (negative quantity)', 422);
        }

        if (in_array($reasonType, ['damage', 'theft', 'expired', 'service_usage'], true) && $delta >= 0) {
            return $this->error('This adjustment type requires a negative quantity', 422);
        }

        if ($reasonType === 'return' && $delta <= 0) {
            return $this->error('Returns must increase stock (positive quantity)', 422);
        }

        $movementType = $this->mapReasonTypeToMovementType($reasonType, $delta);

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

            StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => (int) $data['branch_id'],
                'product_id' => (int) $data['product_id'],
                'type' => $movementType,
                'quantity' => abs($delta),
                'reason' => $reason,
            ]);

            AuditLogger::log($userId, $tenantId, 'inventory.adjusted', [
                'branch_id' => (int) $data['branch_id'],
                'product_id' => (int) $data['product_id'],
                'delta' => $delta,
                'new_quantity' => $newQty,
                'reason' => $reason,
                'reason_type' => $reasonType,
                'movement_type' => $movementType,
            ]);

            DB::commit();

            return $this->success(new InventoryResource($inv->load(['product', 'branch'])), 'Stock updated');
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    private function mapReasonTypeToMovementType(string $reasonType, int $delta): string
    {
        return match ($reasonType) {
            'adjustment' => $delta > 0 ? 'in' : 'out',
            'return' => 'return',
            'damage' => 'damage',
            'theft' => 'theft',
            'expired' => 'expired',
            'transfer' => $delta > 0 ? 'in' : 'out',
            'service_usage' => 'service_usage',
            default => $delta > 0 ? 'in' : 'out',
        };
    }
}
