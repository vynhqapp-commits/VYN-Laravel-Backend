<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\CloseMonthlyPeriodRequest;
use App\Models\LedgerEntry;
use App\Models\MonthlyClosing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MonthlyClosingController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $closings = MonthlyClosing::query()
                ->with('closedBy:id,name,email')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->paginate(24);

            $items = collect($closings->items())->map(fn (MonthlyClosing $c) => $this->format($c));

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $items,
                'meta'    => [
                    'current_page' => $closings->currentPage(),
                    'per_page'     => $closings->perPage(),
                    'total'        => $closings->total(),
                    'last_page'    => $closings->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function close(CloseMonthlyPeriodRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $userId   = auth('api')->id();
        $year     = (int) $data['year'];
        $month    = (int) $data['month'];

        // Prevent closing a future period
        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        if ($periodStart->isFuture()) {
            return $this->error('Cannot close a future period.', 422);
        }

        $existing = MonthlyClosing::query()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing && $existing->status === 'closed') {
            return $this->error(
                sprintf('Period %04d-%02d is already closed.', $year, $month),
                422
            );
        }

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end   = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        DB::beginTransaction();
        try {
            // Lock all ledger entries for this period
            LedgerEntry::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('entry_date', [$start, $end])
                ->update(['is_locked' => true]);

            // Upsert the closing record
            $closing = MonthlyClosing::updateOrCreate(
                ['tenant_id' => $tenantId, 'year' => $year, 'month' => $month],
                [
                    'status'    => 'closed',
                    'closed_by' => $userId,
                    'closed_at' => now(),
                    'notes'     => $data['notes'] ?? null,
                ]
            );

            DB::commit();

            return $this->success($this->format($closing->load('closedBy')), 'Period closed successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    private function format(MonthlyClosing $c): array
    {
        return [
            'id'         => (string) $c->id,
            'year'       => $c->year,
            'month'      => $c->month,
            'period'     => sprintf('%04d-%02d', $c->year, $c->month),
            'status'     => $c->status,
            'notes'      => $c->notes,
            'closed_by'  => $c->closed_by ? (string) $c->closed_by : null,
            'closed_by_name' => $c->closedBy?->name,
            'closed_at'  => optional($c->closed_at)->toISOString(),
            'created_at' => optional($c->created_at)->toISOString(),
        ];
    }
}
