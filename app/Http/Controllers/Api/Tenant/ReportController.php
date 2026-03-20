<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Inventory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function profitLoss(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'period' => 'required|date_format:Y-m',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        [$year, $month] = array_map('intval', explode('-', $data['period']));
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $q = LedgerEntry::query()
            ->whereBetween('entry_date', [$start, $end]);

        if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);

        $entries = $q->get(['type', 'category', 'amount']);

        $revenue = (float) $entries->where('type', 'revenue')->sum('amount');
        $refunds = (float) $entries->where('type', 'refund')->sum('amount'); // negative numbers
        $expenses = (float) $entries->where('type', 'expense')->sum('amount');
        $commission = (float) $entries
            ->filter(fn ($e) => (string) $e->type === 'expense' && in_array((string) ($e->category ?? ''), ['staff_commission', 'staff_tips'], true))
            ->sum('amount');
        $commissionReversal = (float) $entries
            ->filter(fn ($e) => (string) $e->type === 'refund' && in_array((string) ($e->category ?? ''), ['staff_commission', 'staff_tips'], true))
            ->sum('amount');
        $commission = $commission + $commissionReversal;
        $operatingExpense = $expenses - ((float) $entries
            ->filter(fn ($e) => (string) $e->type === 'expense' && in_array((string) ($e->category ?? ''), ['staff_commission', 'staff_tips'], true))
            ->sum('amount'));

        $netRevenue = $revenue + $refunds;
        $profit = $netRevenue - $operatingExpense - $commission;

        // Breakdown by type/category for drill-down UIs
        $byType = LedgerEntry::query()
            ->select('type', DB::raw('SUM(amount) as total'))
            ->whereBetween('entry_date', [$start, $end])
            ->when(!empty($data['branch_id']), fn ($qq) => $qq->where('branch_id', $data['branch_id']))
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'total' => round((float) $r->total, 2)])
            ->values();

        $byCategory = LedgerEntry::query()
            ->select('type', 'category', DB::raw('SUM(amount) as total'))
            ->whereBetween('entry_date', [$start, $end])
            ->when(!empty($data['branch_id']), fn ($qq) => $qq->where('branch_id', $data['branch_id']))
            ->groupBy('type', 'category')
            ->orderBy('type')
            ->orderBy('category')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'category' => $r->category, 'total' => round((float) $r->total, 2)])
            ->values();

        return $this->success([
            'period' => $data['period'],
            'revenue' => round($netRevenue, 2),
            'expense' => round($operatingExpense, 2),
            'commission' => round($commission, 2),
            'profit' => round($profit, 2),
            'entries' => [
                'by_type' => $byType,
                'by_category' => $byCategory,
            ],
        ]);
    }

    public function vat(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'period' => 'required|date_format:Y-m',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        [$year, $month] = array_map('intval', explode('-', $data['period']));
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $q = LedgerEntry::query()
            ->whereIn('type', ['revenue', 'refund'])
            ->whereBetween('entry_date', [$start, $end]);

        if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);

        $totalRevenue = (float) $q->sum('amount'); // refunds are negative
        $estimatedVat = null;

        $tenantId = auth('api')->user()?->tenant_id;
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;
        $vatRate = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        if ($vatRate !== null && $vatRate > 0) {
            $estimatedVat = (float) $q->sum('tax_amount');
        }

        return $this->success([
            'period' => $data['period'],
            'total_revenue' => round($totalRevenue, 2),
            'vat_rate' => $vatRate,
            'estimated_vat' => $estimatedVat !== null ? round($estimatedVat, 2) : null,
            'vat_report' => [],
        ]);
    }

    public function paymentBreakdown(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $paymentQuery = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->with(['invoice:id,branch_id']);

        if (!empty($data['branch_id'])) {
            $branchId = (int) $data['branch_id'];
            $paymentQuery->whereHas('invoice', fn ($q) => $q->where('branch_id', $branchId));
        }

        $payments = $paymentQuery->get(['method', 'amount', 'invoice_id']);

        $byMethod = [];
        foreach ($payments as $p) {
            $method = (string) $p->method;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + (float) $p->amount;
        }

        return $this->success([
            'from' => $data['from'],
            'to' => $data['to'],
            'by_method' => collect($byMethod)->map(fn ($amt) => round((float) $amt, 2))->toArray(),
            'transactions' => $payments->count(),
        ]);
    }

    public function inventoryMovement(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                'branch_id' => 'nullable|exists:branches,id',
                'product_id' => 'nullable|exists:products,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $movements = StockMovement::query()
            ->with(['product:id,name', 'branch:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when(!empty($data['branch_id']), fn ($q) => $q->where('branch_id', $data['branch_id']))
            ->when(!empty($data['product_id']), fn ($q) => $q->where('product_id', $data['product_id']))
            ->latest('id')
            ->limit(500)
            ->get();

        $summary = [
            'in' => (int) $movements->where('type', 'in')->sum('quantity'),
            'out' => (int) $movements->whereIn('type', ['out', 'service_deduction'])->sum('quantity'),
            'net' => 0,
        ];
        $summary['net'] = $summary['in'] - $summary['out'];

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

    public function lowStock(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = Inventory::query()
                ->with(['product', 'branch'])
                ->when(!empty($data['branch_id']), fn ($qq) => $qq->where('branch_id', $data['branch_id']));

            $rows = $q->get()->filter(function (Inventory $inv) {
                $threshold = $inv->low_stock_threshold;
                if ($threshold === null) {
                    $threshold = $inv->product?->low_stock_threshold;
                }
                if ($threshold === null) return false;
                return (int) $inv->quantity <= (int) $threshold;
            })->values();

            return $this->success(\App\Http\Resources\InventoryResource::collection($rows));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function profitLossExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $data = $request->validate([
                'period'    => 'required|date_format:Y-m',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        [$year, $month] = array_map('intval', explode('-', $data['period']));
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end   = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $q = LedgerEntry::query()->whereBetween('entry_date', [$start, $end]);
        if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);
        $entries = $q->get(['type', 'category', 'amount']);

        $revenue          = (float) $entries->where('type', 'revenue')->sum('amount');
        $refunds          = (float) $entries->where('type', 'refund')->sum('amount');
        $expenses         = (float) $entries->where('type', 'expense')->sum('amount');
        $commissionFilter = fn ($e) => (string) $e->type === 'expense' && in_array((string) ($e->category ?? ''), ['staff_commission', 'staff_tips'], true);
        $commissionAmt    = (float) $entries->filter($commissionFilter)->sum('amount');
        $commissionRevAmt = (float) $entries->filter(fn ($e) => (string) $e->type === 'refund' && in_array((string) ($e->category ?? ''), ['staff_commission', 'staff_tips'], true))->sum('amount');
        $commission       = $commissionAmt + $commissionRevAmt;
        $operatingExpense = $expenses - (float) $entries->filter($commissionFilter)->sum('amount');
        $netRevenue       = $revenue + $refunds;
        $profit           = $netRevenue - $operatingExpense - $commission;

        $byCategory = LedgerEntry::query()
            ->select('type', 'category', DB::raw('SUM(amount) as total'))
            ->whereBetween('entry_date', [$start, $end])
            ->when(!empty($data['branch_id']), fn ($qq) => $qq->where('branch_id', $data['branch_id']))
            ->groupBy('type', 'category')
            ->orderBy('type')->orderBy('category')
            ->get();

        $period = $data['period'];

        return response()->streamDownload(function () use ($period, $netRevenue, $operatingExpense, $commission, $profit, $byCategory) {
            $fp = fopen('php://output', 'w');

            fputcsv($fp, ['Profit & Loss Report — ' . $period]);
            fputcsv($fp, []);
            fputcsv($fp, ['Summary']);
            fputcsv($fp, ['Metric', 'Amount']);
            fputcsv($fp, ['Net Revenue',        number_format($netRevenue, 2, '.', '')]);
            fputcsv($fp, ['Operating Expenses', number_format($operatingExpense, 2, '.', '')]);
            fputcsv($fp, ['Commission & Tips',  number_format($commission, 2, '.', '')]);
            fputcsv($fp, ['Net Profit',         number_format($profit, 2, '.', '')]);
            fputcsv($fp, []);
            fputcsv($fp, ['Detail by Category']);
            fputcsv($fp, ['Type', 'Category', 'Total']);
            foreach ($byCategory as $row) {
                fputcsv($fp, [$row->type, $row->category ?? '', number_format((float) $row->total, 2, '.', '')]);
            }

            fclose($fp);
        }, "pnl-{$period}.csv", [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"pnl-{$period}.csv\"",
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    public function vatExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $data = $request->validate([
                'period'    => 'required|date_format:Y-m',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        [$year, $month] = array_map('intval', explode('-', $data['period']));
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end   = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $tenantId = auth('api')->user()?->tenant_id;
        $tenant   = $tenantId ? Tenant::query()->find($tenantId) : null;
        $vatRate  = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        $q = LedgerEntry::query()
            ->whereIn('type', ['revenue', 'refund'])
            ->whereBetween('entry_date', [$start, $end]);
        if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);

        $totalRevenue = (float) $q->sum('amount');
        $estimatedVat = ($vatRate !== null && $vatRate > 0) ? (float) $q->sum('tax_amount') : null;

        $period = $data['period'];

        return response()->streamDownload(function () use ($period, $totalRevenue, $vatRate, $estimatedVat) {
            $fp = fopen('php://output', 'w');

            fputcsv($fp, ['VAT Report — ' . $period]);
            fputcsv($fp, []);
            fputcsv($fp, ['Metric', 'Value']);
            fputcsv($fp, ['Period',         $period]);
            fputcsv($fp, ['Total Revenue',  number_format($totalRevenue, 2, '.', '')]);
            fputcsv($fp, ['VAT Rate (%)',   $vatRate !== null ? number_format($vatRate, 2, '.', '') : 'N/A']);
            fputcsv($fp, ['Estimated VAT',  $estimatedVat !== null ? number_format($estimatedVat, 2, '.', '') : 'N/A']);

            fclose($fp);
        }, "vat-{$period}.csv", [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"vat-{$period}.csv\"",
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    public function paymentBreakdownExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $data = $request->validate([
                'from'      => 'required|date_format:Y-m-d',
                'to'        => 'required|date_format:Y-m-d|after_or_equal:from',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $q = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to]);

        if (!empty($data['branch_id'])) {
            $branchId = (int) $data['branch_id'];
            $q->whereHas('invoice', fn ($qq) => $qq->where('branch_id', $branchId));
        }

        $payments = $q->get(['method', 'amount', 'created_at']);

        $byMethod = [];
        foreach ($payments as $p) {
            $method             = (string) $p->method;
            $byMethod[$method]  = ($byMethod[$method] ?? 0) + (float) $p->amount;
        }

        $fromStr = $data['from'];
        $toStr   = $data['to'];

        return response()->streamDownload(function () use ($fromStr, $toStr, $byMethod, $payments) {
            $fp = fopen('php://output', 'w');

            fputcsv($fp, ["Payment Breakdown — {$fromStr} to {$toStr}"]);
            fputcsv($fp, []);
            fputcsv($fp, ['Payment Method', 'Total Amount', 'Transactions']);

            $countByMethod = [];
            foreach ($payments as $p) {
                $m = (string) $p->method;
                $countByMethod[$m] = ($countByMethod[$m] ?? 0) + 1;
            }

            foreach ($byMethod as $method => $total) {
                fputcsv($fp, [$method, number_format($total, 2, '.', ''), $countByMethod[$method] ?? 0]);
            }

            fputcsv($fp, []);
            fputcsv($fp, ['Total Transactions', '', $payments->count()]);

            fclose($fp);
        }, "payments-{$fromStr}-{$toStr}.csv", [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"payments-{$fromStr}-{$toStr}.csv\"",
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    public function margins(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $invoiceIdsQuery = Invoice::query()
            ->whereBetween('created_at', [$from, $to]);
        if (!empty($data['branch_id'])) $invoiceIdsQuery->where('branch_id', $data['branch_id']);

        $invoiceIds = $invoiceIdsQuery->pluck('id');

        $items = InvoiceItem::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['itemable_type', 'itemable_id', 'name', 'quantity', 'unit_price', 'total']);

        $serviceIdToCost = Service::query()->whereIn('id', $items->where('itemable_type', Service::class)->pluck('itemable_id'))->pluck('cost', 'id');
        $productIdToCost = Product::query()->whereIn('id', $items->where('itemable_type', Product::class)->pluck('itemable_id'))->pluck('cost', 'id');

        $rowsByKey = [];
        foreach ($items as $item) {
            $key = $item->itemable_type . ':' . $item->itemable_id;
            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = [
                    'type' => $item->itemable_type === Service::class ? 'service' : 'product',
                    'id' => (string) $item->itemable_id,
                    'name' => (string) $item->name,
                    'revenue' => 0.0,
                    'cost' => 0.0,
                ];
            }

            $rowsByKey[$key]['revenue'] += (float) $item->total;

            $unitCost = 0.0;
            if ($item->itemable_type === Service::class) {
                $unitCost = (float) ($serviceIdToCost[$item->itemable_id] ?? 0);
            } elseif ($item->itemable_type === Product::class) {
                $unitCost = (float) ($productIdToCost[$item->itemable_id] ?? 0);
            }
            $rowsByKey[$key]['cost'] += $unitCost * (int) $item->quantity;
        }

        $rows = array_values($rowsByKey);
        usort($rows, fn ($a, $b) => ($b['revenue'] <=> $a['revenue']));

        $rows = array_map(function ($r) {
            $revenue = (float) $r['revenue'];
            $cost = (float) $r['cost'];
            $margin = $revenue - $cost;
            $marginPct = $revenue > 0 ? ($margin / $revenue) * 100 : 0;

            return [
                'type' => $r['type'],
                'id' => $r['id'],
                'name' => $r['name'],
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'margin' => round($margin, 2),
                'margin_pct' => round($marginPct, 2),
            ];
        }, $rows);

        return $this->success([
            'from' => $data['from'],
            'to' => $data['to'],
            'rows' => $rows,
        ]);
    }
}

