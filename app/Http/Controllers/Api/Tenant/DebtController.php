<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\AddDebtPaymentRequest;
use App\Http\Requests\Api\Tenant\IndexDebtsRequest;
use App\Http\Requests\Api\Tenant\ListDebtWriteOffRequestsRequest;
use App\Http\Requests\Api\Tenant\WriteOffDebtRequest;
use App\Http\Resources\DebtLedgerEntryResource;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtLedgerEntry;
use App\Models\DebtWriteOffRequest;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DebtController extends Controller
{
    public function index(IndexDebtsRequest $request): JsonResponse
    {
        $data = $request->validated();

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

    public function addPayment(AddDebtPaymentRequest $request, Debt $debt): JsonResponse
    {
        $data = $request->validated();

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

    public function writeOff(WriteOffDebtRequest $request, Debt $debt): JsonResponse
    {
        $payload = $request->validated();

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

            $existingPending = DebtWriteOffRequest::query()
                ->where('debt_id', $debt->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingPending) {
                DB::rollBack();
                return $this->error('A pending write-off request already exists', 422);
            }

            $req = DebtWriteOffRequest::create([
                'tenant_id' => $tenantId,
                'debt_id' => $debt->id,
                'requested_by' => $userId,
                'amount' => $amount,
                'reason' => $payload['reason'] ?? 'Write-off',
                'status' => !empty($payload['submit_for_approval']) ? 'pending' : 'approved',
            ]);

            if (!empty($payload['submit_for_approval'])) {
                AuditLogger::log($userId, $tenantId, 'debt.writeoff.requested', [
                    'debt_id' => $debt->id,
                    'request_id' => $req->id,
                    'amount' => $amount,
                ]);
            } else {
                $debt->update([
                    'remaining_amount' => 0,
                    'status' => 'settled',
                ]);

                DebtLedgerEntry::create([
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

                $req->update(['approved_by' => $userId]);
                AuditLogger::log($userId, $tenantId, 'debt.writeoff.approved', [
                    'debt_id' => $debt->id,
                    'request_id' => $req->id,
                    'amount' => $amount,
                ]);
            }

            DB::commit();
            return $this->created([
                'request_id' => (string) $req->id,
                'status' => (string) $req->status,
            ], !empty($payload['submit_for_approval']) ? 'Write-off request submitted' : 'Write-off approved');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function writeOffRequests(ListDebtWriteOffRequestsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rows = DebtWriteOffRequest::query()
            ->with(['debt:id,customer_id,remaining_amount,status', 'requestedBy:id,name', 'approvedBy:id,name'])
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->latest()
            ->limit(200)
            ->get();

        return $this->success($rows);
    }

    public function approveWriteOff(DebtWriteOffRequest $requestItem): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        DB::beginTransaction();
        try {
            if ((int) $requestItem->tenant_id !== (int) $tenantId) {
                DB::rollBack();
                return $this->error('Not found', 404);
            }
            if ((string) $requestItem->status !== 'pending') {
                DB::rollBack();
                return $this->error('Only pending requests can be approved', 422);
            }

            $debt = Debt::query()->lockForUpdate()->findOrFail($requestItem->debt_id);
            if ((int) $debt->tenant_id !== (int) $tenantId) {
                DB::rollBack();
                return $this->error('Not found', 404);
            }

            $amount = min((float) $requestItem->amount, (float) $debt->remaining_amount);
            if ($amount <= 0.009) {
                DB::rollBack();
                return $this->error('Nothing to write off', 422);
            }

            $newRemaining = max(0.0, (float) $debt->remaining_amount - $amount);
            $debt->update([
                'remaining_amount' => $newRemaining,
                'status' => $newRemaining <= 0.009 ? 'settled' : 'partial',
            ]);

            $entry = DebtLedgerEntry::create([
                'tenant_id' => $tenantId,
                'customer_id' => $debt->customer_id,
                'debt_id' => $debt->id,
                'invoice_id' => $debt->invoice_id,
                'type' => 'write_off',
                'amount' => $amount * -1,
                'balance_after' => $newRemaining,
                'notes' => 'Debt write-off approved',
                'created_by' => $userId,
            ]);

            $requestItem->update([
                'status' => 'approved',
                'approved_by' => $userId,
            ]);

            AuditLogger::log($userId, $tenantId, 'debt.writeoff.approved', [
                'debt_id' => $debt->id,
                'request_id' => $requestItem->id,
                'amount' => $amount,
            ]);

            DB::commit();
            return $this->success(new DebtLedgerEntryResource($entry), 'Write-off approved');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rejectWriteOff(DebtWriteOffRequest $requestItem): JsonResponse
    {
        $tenantId = auth('api')->user()?->tenant_id;
        $userId = auth('api')->id();
        if (!$tenantId || !$userId) return $this->error('Tenant required', 422);

        if ((int) $requestItem->tenant_id !== (int) $tenantId) {
            return $this->error('Not found', 404);
        }
        if ((string) $requestItem->status !== 'pending') {
            return $this->error('Only pending requests can be rejected', 422);
        }

        $requestItem->update([
            'status' => 'rejected',
            'approved_by' => $userId,
        ]);

        AuditLogger::log($userId, $tenantId, 'debt.writeoff.rejected', [
            'debt_id' => $requestItem->debt_id,
            'request_id' => $requestItem->id,
            'amount' => (float) $requestItem->amount,
        ]);

        return $this->success([
            'request_id' => (string) $requestItem->id,
            'status' => 'rejected',
        ], 'Write-off rejected');
    }
}

