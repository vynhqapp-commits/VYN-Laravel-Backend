<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'nullable|exists:branches,id',
                'from'      => 'nullable|date_format:Y-m-d',
                'to'        => 'nullable|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = Expense::query()->with('branch')->latest('expense_date')->latest('id');

            if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);

            if (!empty($data['from']) || !empty($data['to'])) {
                $from = !empty($data['from']) ? Carbon::parse($data['from'])->toDateString() : null;
                $to = !empty($data['to']) ? Carbon::parse($data['to'])->toDateString() : null;
                if ($from && $to) $q->whereBetween('expense_date', [$from, $to]);
                elseif ($from) $q->where('expense_date', '>=', $from);
                elseif ($to) $q->where('expense_date', '<=', $to);
            }

            $expenses = $q->paginate(50);
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => ExpenseResource::collection($expenses->items()),
                'meta' => [
                    'current_page' => $expenses->currentPage(),
                    'per_page' => $expenses->perPage(),
                    'total' => $expenses->total(),
                    'last_page' => $expenses->lastPage(),
                    'from' => $expenses->firstItem(),
                    'to' => $expenses->lastItem(),
                ],
                'links' => [
                    'first' => $expenses->url(1),
                    'last' => $expenses->url($expenses->lastPage()),
                    'prev' => $expenses->previousPageUrl(),
                    'next' => $expenses->nextPageUrl(),
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
                'branch_id' => 'nullable|exists:branches,id',
                'category' => 'required|string|max:80',
                'amount' => 'required|numeric|min:0.01',
                'expense_date' => 'required|date_format:Y-m-d',
                'description' => 'nullable|string|max:255',
                'payment_method' => 'nullable|string|max:50',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        // Soft-lock guard: reject entries into a closed period
        try {
            LedgerService::assertNotLocked((int) $tenantId, $data['expense_date']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        DB::beginTransaction();
        try {
            $expense = Expense::create([
                'tenant_id' => $tenantId,
                'branch_id' => $data['branch_id'] ?? null,
                'category' => $data['category'],
                'description' => $data['description'] ?? $data['category'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'payment_method' => $data['payment_method'] ?? null,
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'branch_id' => $expense->branch_id,
                'type' => 'expense',
                'category' => $expense->category,
                'amount' => (float) $expense->amount,
                'tax_amount' => 0,
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'description' => $expense->description,
                'entry_date' => Carbon::parse($expense->expense_date)->toDateString(),
                'is_locked' => false,
            ]);

            DB::commit();
            return $this->created(new ExpenseResource($expense->load('branch')), 'Expense recorded');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'nullable|exists:branches,id',
                'category' => 'sometimes|string|max:80',
                'amount' => 'sometimes|numeric|min:0.01',
                'expense_date' => 'sometimes|date_format:Y-m-d',
                'description' => 'nullable|string|max:255',
                'payment_method' => 'nullable|string|max:50',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            $expense->update($data);

            // Update the matching ledger entry (if unlocked)
            $ledger = LedgerEntry::query()
                ->where('reference_type', Expense::class)
                ->where('reference_id', $expense->id)
                ->where('type', 'expense')
                ->latest('id')
                ->first();

            if ($ledger && !$ledger->is_locked) {
                $ledger->update([
                    'branch_id' => $expense->branch_id,
                    'category' => $expense->category,
                    'amount' => (float) $expense->amount,
                    'tax_amount' => 0,
                    'description' => $expense->description,
                    'entry_date' => Carbon::parse($expense->expense_date)->toDateString(),
                ]);
            }

            DB::commit();
            return $this->success(new ExpenseResource($expense->load('branch')), 'Expense updated');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            // Create reversal ledger entry for audit (append-only).
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'branch_id' => $expense->branch_id,
                'type' => 'expense',
                'category' => $expense->category,
                'amount' => (float) $expense->amount * -1,
                'tax_amount' => 0,
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'description' => 'Expense reversal' . ($expense->description ? (': ' . $expense->description) : ''),
                'entry_date' => Carbon::parse($expense->expense_date)->toDateString(),
                'is_locked' => false,
            ]);

            $expense->delete(); // soft delete
            DB::commit();

            return $this->success(null, 'Expense deleted');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }
}

