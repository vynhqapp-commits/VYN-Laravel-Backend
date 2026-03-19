<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashDrawerSessionResource;
use App\Http\Resources\CashMovementResource;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashDrawerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'status' => 'nullable|in:open,closed,reconciled',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            $drawer = CashDrawer::query()
                ->where('branch_id', $data['branch_id'])
                ->first();

            if (!$drawer) {
                return $this->success([]);
            }

            $q = $drawer->sessions()->with(['movements', 'cashDrawer'])->latest('opened_at');
            if (!empty($data['status'])) $q->where('status', $data['status']);

            $sessions = $q->limit(200)->get();
            return $this->success(CashDrawerSessionResource::collection($sessions));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function open(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'opening_balance' => 'nullable|numeric|min:0',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            $drawer = CashDrawer::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'branch_id' => $data['branch_id'],
            ], [
                'name' => 'Main drawer',
                'is_active' => true,
            ]);

            $existing = $drawer->sessions()->where('status', 'open')->first();
            if ($existing) {
                DB::rollBack();
                return $this->error('A cash drawer session is already open for this location', 422);
            }

            $session = CashDrawerSession::create([
                'cash_drawer_id' => $drawer->id,
                'opened_by' => $userId,
                'opening_balance' => (float) ($data['opening_balance'] ?? 0),
                'opened_at' => Carbon::now(),
                'status' => 'open',
            ]);

            DB::commit();
            return $this->created(new CashDrawerSessionResource($session->load(['movements', 'cashDrawer'])));
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function transaction(Request $request, CashDrawerSession $session): JsonResponse
    {
        try {
            $data = $request->validate([
                'type' => 'required|in:cash_in,cash_out',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        try {
            $session->loadMissing('cashDrawer');
            if ((int) $session->cashDrawer?->tenant_id !== (int) $tenantId) {
                return $this->error('Not found', 404);
            }
            if ($session->status !== 'open') return $this->error('Session is not open', 422);

            $movement = CashMovement::create([
                'tenant_id' => $tenantId,
                'cash_drawer_session_id' => $session->id,
                'type' => $data['type'],
                'amount' => (float) $data['amount'],
                'reason' => $data['reason'] ?? null,
                'created_by' => $userId,
            ]);

            return $this->created(new CashMovementResource($movement), 'Movement recorded');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function close(Request $request, CashDrawerSession $session): JsonResponse
    {
        try {
            $data = $request->validate([
                'actual_cash' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            $session->loadMissing(['cashDrawer', 'movements']);
            if ((int) $session->cashDrawer?->tenant_id !== (int) $tenantId) {
                DB::rollBack();
                return $this->error('Not found', 404);
            }
            if ($session->status !== 'open') {
                DB::rollBack();
                return $this->error('Session is not open', 422);
            }

            $cashPayments = Payment::query()
                ->where('cash_drawer_session_id', $session->id)
                ->where('method', 'cash')
                ->where('status', 'completed')
                ->sum('amount');

            $cashIn = (float) $session->movements->where('type', 'cash_in')->sum('amount');
            $cashOut = (float) $session->movements->where('type', 'cash_out')->sum('amount');

            $opening = (float) $session->opening_balance;
            $expected = $opening + (float) $cashPayments + $cashIn - $cashOut;
            $actual = (float) $data['actual_cash'];
            $discrepancy = $actual - $expected;

            $session->update([
                'closing_balance' => $actual,
                'expected_balance' => $expected,
                'discrepancy' => $discrepancy,
                'closed_by' => $userId,
                'closed_at' => Carbon::now(),
                'status' => 'closed',
            ]);

            // Ledger posting for over/short
            if (abs($discrepancy) >= 0.01) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $session->cashDrawer->branch_id,
                    'type' => 'adjustment',
                    'category' => 'cash_over_short',
                    'amount' => $discrepancy,
                    'tax_amount' => 0,
                    'reference_type' => CashDrawerSession::class,
                    'reference_id' => $session->id,
                    'description' => 'Cash drawer over/short',
                    'entry_date' => now()->toDateString(),
                    'is_locked' => false,
                ]);
            }

            DB::commit();
            return $this->success(new CashDrawerSessionResource($session->fresh()->load(['movements', 'cashDrawer'])), 'Session closed');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function approve(Request $request, CashDrawerSession $session): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            $session->loadMissing(['cashDrawer', 'movements']);
            if ((int) $session->cashDrawer?->tenant_id !== (int) $tenantId) {
                return $this->error('Not found', 404);
            }
            if ($session->status !== 'closed') return $this->error('Only closed sessions can be reconciled', 422);

            $session->update(['status' => 'reconciled']);
            return $this->success(new CashDrawerSessionResource($session->fresh()->load(['movements', 'cashDrawer'])), 'Session reconciled');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

