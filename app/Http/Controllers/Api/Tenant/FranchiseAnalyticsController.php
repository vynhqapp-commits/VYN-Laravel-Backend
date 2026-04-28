<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FranchiseAnalyticsController extends Controller
{
    /**
     * Return per-branch KPIs for the authenticated salon owner's tenant.
     *
     * Query params (all optional):
     *   from  Y-m-d  defaults to first day of current month
     *   to    Y-m-d  defaults to today
     */
    public function kpis(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => 'nullable|date_format:Y-m-d',
                'to'   => 'nullable|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to   = Carbon::parse($data['to']   ?? now()->toDateString())->endOfDay();

        try {
            $user = auth('api')->user();
            $tenantId = (int) ($user?->tenant_id ?? 0);

            $branchIds = null;
            if ($user && method_exists($user, 'hasRole') && $user->hasRole('franchise_owner')) {
                $branchIds = DB::table('franchise_owner_branches')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', (int) $user->id)
                    ->pluck('branch_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            // ── Fetch branches for this viewer ──────────────────────────────
            $branchesQ = Branch::query()->select(['id', 'name', 'is_active']);
            if (is_array($branchIds)) {
                $branchesQ->whereIn('id', $branchIds);
            }
            $branches = $branchesQ->get();

            if ($branches->isEmpty()) {
                return $this->success([
                    'from'      => $from->toDateString(),
                    'to'        => $to->toDateString(),
                    'locations' => [],
                    'summary'   => [
                        'total_revenue'         => 0,
                        'location_count'        => 0,
                        'average_per_location'  => 0,
                        'underperforming_count' => 0,
                        'underperforming'       => [],
                    ],
                ]);
            }

            // ── Revenue by branch (ledger_entries type=revenue) ─────────────
            $revenueByBranch = LedgerEntry::query()
                ->select('branch_id', DB::raw('SUM(amount) as total'))
                ->where('type', 'revenue')
                ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
                ->when(is_array($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            // ── Appointment counts by branch + status ────────────────────────
            $appointmentsByBranch = Appointment::query()
                ->select('branch_id', 'status', DB::raw('COUNT(*) as cnt'))
                ->whereBetween('starts_at', [$from, $to])
                ->when(is_array($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->groupBy('branch_id', 'status')
                ->get()
                ->groupBy('branch_id');

            // ── Transaction count + paid totals by branch (invoices) ─────────
            $invoicesByBranch = Invoice::query()
                ->select(
                    'branch_id',
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('SUM(total) as gross_total'),
                )
                ->whereBetween('created_at', [$from, $to])
                ->when(is_array($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            // ── Build per-branch KPI rows ────────────────────────────────────
            $locations = $branches->map(function (Branch $branch) use (
                $revenueByBranch,
                $appointmentsByBranch,
                $invoicesByBranch,
            ) {
                $branchId = $branch->id;

                $revenue = round((float) ($revenueByBranch[$branchId]->total ?? 0), 2);

                $aptGroups        = $appointmentsByBranch[$branchId] ?? collect();
                $totalApts        = (int) $aptGroups->sum('cnt');
                $completedApts    = (int) ($aptGroups->firstWhere('status', 'completed')?->cnt ?? 0);
                $bookingVolume    = $totalApts; // all statuses scheduled for the period
                $utilizationRate  = $totalApts > 0
                    ? round(($completedApts / $totalApts) * 100, 1)
                    : 0.0;

                $invRow          = $invoicesByBranch[$branchId] ?? null;
                $transactionCount = (int) ($invRow->transaction_count ?? 0);
                $avgTicket        = $transactionCount > 0
                    ? round((float) ($invRow->gross_total ?? 0) / $transactionCount, 2)
                    : 0.0;

                return [
                    'id'                     => (string) $branchId,
                    'name'                   => $branch->name,
                    'status'                 => $branch->is_active ? 'active' : 'inactive',
                    'revenue'                => $revenue,
                    'transaction_count'      => $transactionCount,
                    'booking_volume'         => $bookingVolume,
                    'completed_appointments' => $completedApts,
                    'avg_ticket'             => $avgTicket,
                    'utilization_rate'       => $utilizationRate,
                    'is_underperforming'     => false, // computed below
                ];
            })->values()->toArray();

            // ── Compute summary & underperforming flag ────────────────────────
            $totalRevenue      = array_sum(array_column($locations, 'revenue'));
            $locationCount     = count($locations);
            $avgPerLocation    = $locationCount > 0 ? round($totalRevenue / $locationCount, 2) : 0;
            $threshold         = $avgPerLocation * 0.70; // 70% of average = underperforming

            foreach ($locations as &$loc) {
                $loc['is_underperforming'] = $locationCount > 1 && $loc['revenue'] < $threshold;
            }
            unset($loc);

            // Sort by revenue descending
            usort($locations, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

            $underperforming      = array_values(array_filter($locations, fn ($l) => $l['is_underperforming']));
            $underperformingCount = count($underperforming);

            return $this->success([
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'locations' => $locations,
                'summary'   => [
                    'total_revenue'         => round($totalRevenue, 2),
                    'location_count'        => $locationCount,
                    'average_per_location'  => $avgPerLocation,
                    'underperforming_count' => $underperformingCount,
                    'underperforming'       => $underperforming,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
