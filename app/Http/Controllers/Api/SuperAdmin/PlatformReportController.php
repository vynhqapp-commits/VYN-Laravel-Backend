<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformReportController extends Controller
{
    public function index()
    {
        try {
            return $this->success([
                'total_tenants'     => Tenant::count(),
                'active_tenants'    => Tenant::where('subscription_status', 'active')->count(),
                'suspended_tenants' => Tenant::where('subscription_status', 'suspended')->count(),
                'tenants_by_plan'   => Tenant::selectRaw('plan, count(*) as total')->groupBy('plan')->get(),
                'total_users'       => User::count(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function financial(Request $request)
    {
        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();

            $rows = LedgerEntry::query()
                ->select('type', 'category', DB::raw('SUM(amount) as total'))
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('type', 'category')
                ->orderBy('type')
                ->orderBy('category')
                ->get()
                ->map(fn ($r) => ['type' => $r->type, 'category' => $r->category, 'total' => round((float) $r->total, 2)])
                ->values();

            return $this->success([
                'from' => $data['from'],
                'to' => $data['to'],
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function franchiseKpis(Request $request)
    {
        try {
            $data = $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();

            $byTenant = LedgerEntry::query()
                ->select('tenant_id', DB::raw("SUM(CASE WHEN type='revenue' THEN amount ELSE 0 END) as revenue"))
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('tenant_id')
                ->get()
                ->keyBy('tenant_id');

            // Booking counts per tenant
            $bookingsByTenant = Appointment::withoutGlobalScopes()
                ->select('tenant_id', DB::raw('COUNT(*) as booking_count'))
                ->whereBetween('starts_at', [$from, $to])
                ->groupBy('tenant_id')
                ->get()
                ->keyBy('tenant_id');

            // Invoice counts per tenant (for avg ticket)
            $invoicesByTenant = Invoice::withoutGlobalScopes()
                ->select('tenant_id', DB::raw('COUNT(*) as invoice_count'), DB::raw('SUM(total) as gross_total'))
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('tenant_id')
                ->get()
                ->keyBy('tenant_id');

            $tenants = Tenant::query()->orderBy('name')->get(['id', 'name', 'plan', 'subscription_status']);
            $rows = $tenants->map(function (Tenant $t) use ($byTenant, $bookingsByTenant, $invoicesByTenant) {
                $rev          = (float) (($byTenant[$t->id]->revenue ?? 0) ?: 0);
                $bookingCount = (int) ($bookingsByTenant[$t->id]->booking_count ?? 0);
                $invRow       = $invoicesByTenant[$t->id] ?? null;
                $invCount     = (int) ($invRow->invoice_count ?? 0);
                $avgTicket    = $invCount > 0 ? round((float) ($invRow->gross_total ?? 0) / $invCount, 2) : 0.0;

                return [
                    'tenant_id'     => (string) $t->id,
                    'tenant_name'   => $t->name,
                    'plan'          => $t->plan,
                    'status'        => $t->subscription_status,
                    'revenue'       => round($rev, 2),
                    'booking_count' => $bookingCount,
                    'avg_ticket'    => $avgTicket,
                ];
            })->values();

            return $this->success([
                'from' => $data['from'],
                'to' => $data['to'],
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
