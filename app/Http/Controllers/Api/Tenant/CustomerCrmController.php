<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\CustomerServicePackage;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerCrmController extends Controller
{
    private function packageResource(CustomerServicePackage $p): array
    {
        return [
            'id'                => (string) $p->id,
            'name'              => $p->name,
            'total_services'   => (int) $p->total_services,
            'remaining_services'=> (int) $p->remaining_services,
            'expires_at'       => $p->expires_at ? Carbon::parse($p->expires_at)->format('Y-m-d') : null,
            'status'            => $p->status,
            'membership_id'    => $p->membership_id ? (string) $p->membership_id : null,
        ];
    }

    private function membershipResource(CustomerMembership $m): array
    {
        return [
            'id'                          => (string) $m->id,
            'name'                        => $m->name,
            'plan'                        => $m->plan,
            'start_date'                  => $m->start_date ? Carbon::parse($m->start_date)->format('Y-m-d') : null,
            'renewal_date'                => $m->renewal_date ? Carbon::parse($m->renewal_date)->format('Y-m-d') : null,
            'interval_months'            => (int) $m->interval_months,
            'service_credits_per_renewal'=> (int) $m->service_credits_per_renewal,
            'remaining_services'         => (int) $m->remaining_services,
            'status'                      => $m->status,
            'auto_renew'                  => (bool) $m->auto_renew,
        ];
    }

    public function packages(Customer $customer): JsonResponse
    {
        $packages = CustomerServicePackage::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('expires_at')
            ->orderByDesc('id')
            ->get();

        return $this->success($packages->map(fn (CustomerServicePackage $p) => $this->packageResource($p))->values());
    }

    public function memberships(Customer $customer): JsonResponse
    {
        $memberships = CustomerMembership::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('renewal_date')
            ->orderByDesc('id')
            ->get();

        return $this->success($memberships->map(fn (CustomerMembership $m) => $this->membershipResource($m))->values());
    }

    public function stats(Customer $customer): JsonResponse
    {
        // “Total spent / avg ticket” based on actual collected amount (paid_amount),
        // excluding draft/refunded/void invoices.
        $invoicesQ = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['paid', 'partial']);

        $invoiceCount = (int) $invoicesQ->count();
        $totalSpent = (float) $invoicesQ->sum('paid_amount');
        $avgTicket = $invoiceCount > 0 ? round($totalSpent / $invoiceCount, 2) : 0.0;

        return $this->success([
            'total_spent' => $totalSpent,
            'invoice_count' => $invoiceCount,
            'avg_ticket' => $avgTicket,
        ]);
    }

    public function consumePackage(Request $request, Customer $customer, CustomerServicePackage $package): JsonResponse
    {
        try {
            $data = $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        if ((int) $package->customer_id !== (int) $customer->id) {
            return $this->error('Package does not belong to this customer', 422);
        }

        $qty = (int) $data['quantity'];

        if ($package->status !== 'active') {
            return $this->error('Package is not active', 422);
        }

        if ($package->remaining_services <= 0) {
            return $this->error('No remaining services in this package', 422);
        }

        $consume = min($qty, (int) $package->remaining_services);
        $package->remaining_services = (int) $package->remaining_services - $consume;
        $package->status = $package->remaining_services <= 0 ? 'exhausted' : $package->status;
        $package->save();

        return $this->success($this->packageResource($package), 'Package consumed');
    }

    public function renewMembership(Request $request, Customer $customer, CustomerMembership $membership): JsonResponse
    {
        if ((int) $membership->customer_id !== (int) $customer->id) {
            return $this->error('Membership does not belong to this customer', 422);
        }

        if ($membership->status !== 'active') {
            return $this->error('Membership is not active', 422);
        }

        $renewalDate = $membership->renewal_date ?: Carbon::today();
        $intervalMonths = max(1, (int) $membership->interval_months);

        // Next renewal date
        $nextRenewal = Carbon::parse($renewalDate)->addMonths($intervalMonths);

        // Reset membership remaining services for the new cycle.
        $membership->remaining_services = (int) $membership->service_credits_per_renewal;
        $membership->renewal_date = $nextRenewal;
        $membership->save();

        // Refresh/ensure there is at least one active package for this membership.
        $qty = (int) $membership->service_credits_per_renewal;

        $pkg = CustomerServicePackage::query()
            ->where('membership_id', $membership->id)
            ->orderByDesc('expires_at')
            ->orderByDesc('id')
            ->first();

        if ($pkg) {
            $pkg->name = $pkg->name ?? $membership->name ?? $membership->plan;
            $pkg->total_services = $qty;
            $pkg->remaining_services = $qty;
            $pkg->expires_at = $membership->renewal_date;
            $pkg->status = 'active';
            $pkg->save();
        } else {
            CustomerServicePackage::query()->create([
                'tenant_id' => auth('api')->user()?->tenant_id,
                'customer_id' => $customer->id,
                'membership_id' => $membership->id,
                'name' => $membership->name ?? $membership->plan,
                'total_services' => $qty,
                'remaining_services' => $qty,
                'expires_at' => $membership->renewal_date,
                'status' => 'active',
            ]);
        }

        return $this->success($this->membershipResource($membership), 'Membership renewed');
    }

    public function toggleAutoRenew(Request $request, Customer $customer, CustomerMembership $membership): JsonResponse
    {
        if ((int) $membership->customer_id !== (int) $customer->id) {
            return $this->error('Membership does not belong to this customer', 422);
        }

        try {
            $data = $request->validate([
                'auto_renew' => 'required|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $membership->update(['auto_renew' => $data['auto_renew']]);

        return $this->success($this->membershipResource($membership), 'Auto-renew updated');
    }
}

