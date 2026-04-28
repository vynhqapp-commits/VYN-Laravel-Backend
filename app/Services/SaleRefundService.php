<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\DebtLedgerEntry;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SaleRefundService
{
    public function refund(Invoice $sale, int $actorUserId, int $tenantId, ?string $refundReason = null): void
    {
        $tenant = Tenant::query()->find($tenantId);
        $vatRate = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        DB::beginTransaction();
        try {
            $sale->load(['payments', 'debt', 'items']);

            if (in_array((string) $sale->status, ['refunded', 'void'], true)) {
                throw new \RuntimeException('Sale already refunded');
            }

            foreach ($sale->payments as $p) {
                $p->update(['status' => 'refunded']);
            }

            if ($sale->debt) {
                $debtRemaining = (float) $sale->debt->remaining_amount;
                $sale->debt->update([
                    'status' => 'void',
                    'remaining_amount' => 0,
                ]);

                DebtLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $sale->debt->customer_id,
                    'debt_id' => $sale->debt->id,
                    'invoice_id' => $sale->id,
                    'type' => 'refund_reversal',
                    'amount' => $debtRemaining * -1,
                    'balance_after' => 0,
                    'notes' => 'Debt reversed due to refund',
                    'created_by' => $actorUserId,
                ]);
            }

            $sale->update([
                'status' => 'refunded',
                'paid_amount' => 0,
            ]);

            $commissionEntries = CommissionEntry::query()->where('invoice_id', $sale->id)->get();
            foreach ($commissionEntries as $ce) {
                /** @var CommissionEntry $ce */
                if ($ce->status !== 'reversed') {
                    $ce->update(['status' => 'reversed']);
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $sale->branch_id,
                        'type' => 'refund',
                        'category' => 'staff_commission',
                        'amount' => ((float) $ce->commission_amount) * -1,
                        'tax_amount' => 0,
                        'reference_type' => CommissionEntry::class,
                        'reference_id' => $ce->id,
                        'description' => 'Commission reversal on refund',
                        'entry_date' => now()->toDateString(),
                        'is_locked' => false,
                    ]);
                }
            }

            $servicesRefund = (float) $sale->items->where('itemable_type', Service::class)->sum('total') * -1;
            $productsRefund = (float) $sale->items->where('itemable_type', Product::class)->sum('total') * -1;

            $refundRows = [
                ['category' => 'services', 'amount' => $servicesRefund],
                ['category' => 'products', 'amount' => $productsRefund],
            ];

            foreach ($refundRows as $row) {
                if (abs((float) $row['amount']) < 0.0001) continue;
                $taxAmount = 0.0;
                if ($vatRate !== null && $vatRate > 0) {
                    $taxAmount = ((float) $row['amount']) * ($vatRate / 100.0);
                }
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $sale->branch_id,
                    'type' => 'refund',
                    'category' => 'pos_' . $row['category'],
                    'amount' => (float) $row['amount'],
                    'tax_amount' => $taxAmount,
                    'reference_type' => Invoice::class,
                    'reference_id' => $sale->id,
                    'description' => $refundReason ?? ('Refund (' . $row['category'] . ')'),
                    'entry_date' => now()->toDateString(),
                    'is_locked' => false,
                ]);
            }

            DB::commit();

            AuditLogger::log($actorUserId, $tenantId, 'sale.refunded', [
                'invoice_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'reason' => $refundReason,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

