<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtLedgerEntryResource;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtLedgerEntry;
use App\Models\DebtWriteOffRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DebtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'customer_id' => 'required|exists:customers,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            $customerId = (int) $data['customer_id'];
            $debts = Debt::query()
                ->where('customer_id', $customerId)
                ->latest()
                ->get(['id', 'remaining_amount', 'status']);

            $debtIds = $debts->pluck('id');
            $entries = DebtLedgerEntry::query()
                ->whereIn('debt_id', $debtIds)
                ->latest()
                ->limit(200)
                ->get();

            $balance = (float) $debts->whereIn('status', ['open', 'partial'])->sum('remaining_amount');

            return $this->success([
                'entries' => DebtLedgerEntryResource::collection($entries),
                'balance' => round($balance, 2),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function addPayment(Request $request, Debt $debt): JsonResponse
    {
        try {
            $data = $request->validate([
                'amount' => 'required|numeric|min:0.01',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            $amount = (float) $data['amount'];
            $debt->refresh();
            if ((int) $debt->tenant_id !== (int) $tenantId) {
                DB::rollBack();
                return $this->error('Not found', 404);
            }
            if (!in_array($debt->status, ['open', 'partial'], true)) {
                DB::rollBack();
                return $this->error('Debt is not payable', 422);
            }

            $newPaid = (float) $debt->paid_amount + $amount;
            $newRemaining = max(0.0, (float) $debt->remaining_amount - $amount);
            $newStatus = $newRemaining <= 0.009 ? 'settled' : 'partial';

            $debt->update([
                'paid_amount' => $newPaid,
                'remaining_amount' => $newRemaining,
                'status' => $newStatus,
            ]);

            $entry = DebtLedgerEntry::create([
                'tenant_id' => $tenantId,
                'customer_id' => $debt->customer_id,
                'debt_id' => $debt->id,
                'invoice_id' => $debt->invoice_id,
                'type' => 'payment',
                'amount' => $amount * -1,
                'balance_after' => $newRemaining,
                'notes' => 'Debt payment',
                'created_by' => $userId,
            ]);

            DB::commit();
            return $this->created(new DebtLedgerEntryResource($entry));
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function agingReport(Request $request): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            $openDebts = Debt::query()
                ->whereIn('status', ['open', 'partial'])
                ->with(['customer'])
                ->get();

            $today = Carbon::today();
            $rows = [];
            $summary = [
                '0_30' => 0.0,
                '31_60' => 0.0,
                '61_90' => 0.0,
                '90_plus' => 0.0,
                'total' => 0.0,
            ];

            $byCustomer = [];
            foreach ($openDebts as $d) {
                $cid = (string) $d->customer_id;
                $ageDays = $d->due_date ? max(0, Carbon::parse($d->due_date)->diffInDays($today, false) * -1) : $d->created_at->diffInDays($today);
                $ageDays = (int) max(0, $ageDays);
                $bucket = $ageDays <= 30 ? '0_30' : ($ageDays <= 60 ? '31_60' : ($ageDays <= 90 ? '61_90' : '90_plus'));

                if (!isset($byCustomer[$cid])) {
                    $byCustomer[$cid] = [
                        'client_id' => $cid,
                        'client' => $d->customer ? ['id' => (string) $d->customer->id, 'name' => $d->customer->name] : null,
                        'balance' => 0.0,
                        'oldest_debt_days' => 0,
                        'bucket' => $bucket,
                    ];
                }
                $byCustomer[$cid]['balance'] += (float) $d->remaining_amount;
                $byCustomer[$cid]['oldest_debt_days'] = max((int) $byCustomer[$cid]['oldest_debt_days'], $ageDays);
                $byCustomer[$cid]['bucket'] = $bucket;
            }

            foreach ($byCustomer as $r) {
                $summary['total'] += (float) $r['balance'];
                $summary[$r['bucket']] += (float) $r['balance'];
                $rows[] = [
                    'client_id' => $r['client_id'],
                    'client' => $r['client'],
                    'balance' => round((float) $r['balance'], 2),
                    'oldest_debt_days' => (int) $r['oldest_debt_days'],
                    'bucket' => str_replace('_', '-', $r['bucket']),
                ];
            }

            return $this->success([
                'aging' => $rows,
                'summary' => collect($summary)->map(fn ($v) => round((float) $v, 2))->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // Frontend currently calls POST /api/debts/{debt}/write-off with empty body.
    // Implement as a request + immediate approval by the current manager/owner.
    public function writeOff(Request $request, Debt $debt): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            $debt->refresh();
            if ((int) $debt->tenant_id !== (int) $tenantId) {
                DB::rollBack();
                return $this->error('Not found', 404);
            }

            $amount = (float) $debt->remaining_amount;
            if ($amount <= 0.009) {
                DB::rollBack();
                return $this->error('Nothing to write off', 422);
            }

            $req = DebtWriteOffRequest::create([
                'tenant_id' => $tenantId,
                'debt_id' => $debt->id,
                'requested_by' => $userId,
                'approved_by' => $userId,
                'amount' => $amount,
                'reason' => 'Write-off',
                'status' => 'approved',
            ]);

            $debt->update([
                'remaining_amount' => 0,
                'status' => 'settled',
            ]);

            $entry = DebtLedgerEntry::create([
                'tenant_id' => $tenantId,
                'customer_id' => $debt->customer_id,
                'debt_id' => $debt->id,
                'invoice_id' => $debt->invoice_id,
                'type' => 'write_off',
                'amount' => $amount * -1,
                'balance_after' => 0,
                'notes' => 'Debt write-off',
                'created_by' => $userId,
            ]);

            DB::commit();
            return $this->created(new DebtLedgerEntryResource($entry), 'Write-off approved');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }
}

