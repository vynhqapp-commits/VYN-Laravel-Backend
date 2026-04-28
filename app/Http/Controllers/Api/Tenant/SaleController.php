<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\Appointment;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Coupon;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\TipAllocation;
use App\Models\DebtLedgerEntry;
use App\Models\CustomerServicePackage;
use App\Models\CustomerMembership;
use App\Models\ServicePackageTemplate;
use App\Models\MembershipPlanTemplate;
use App\Mail\SaleReceiptMail;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Models\ApprovalRequest;
use App\Services\PosCheckoutService;
use App\Services\SaleRefundService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * @group Sales
 *
 * POS sales and receipts APIs.
 *
 * @authenticated
 * @header X-Tenant string required Tenant identifier (ID or slug). Example: 1
 */
class SaleController extends Controller
{
    private function normalizeCommissionType(string $type): string
    {
        return match ($type) {
            'percentage' => 'percent_service',
            'fixed' => 'flat_per_service',
            default => $type,
        };
    }

    private function resolveCommissionRule($rules, int $staffId, ?int $serviceId, array $allowedTypes): ?CommissionRule
    {
        $filtered = $rules->filter(function (CommissionRule $rule) use ($allowedTypes, $serviceId) {
            $normalized = $this->normalizeCommissionType((string) $rule->type);
            if (!in_array($normalized, $allowedTypes, true)) {
                return false;
            }

            if ($serviceId !== null) {
                return (int) ($rule->service_id ?? 0) === $serviceId;
            }

            return $rule->service_id === null;
        })->values();

        return $filtered
            ->sortBy([
                fn (CommissionRule $rule) => $rule->staff_id === $staffId ? 0 : 1,
                fn (CommissionRule $rule) => $serviceId !== null && (int) ($rule->service_id ?? 0) === $serviceId ? 0 : 1,
                fn (CommissionRule $rule) => (int) $rule->id,
            ])
            ->first();
    }

    private function calculateCommissionAmount(CommissionRule $rule, float $baseAmount, int $quantity = 1): float
    {
        $type = $this->normalizeCommissionType((string) $rule->type);
        if ($baseAmount <= 0) {
            return 0.0;
        }

        if ($type === 'percent_service' || $type === 'percent_product') {
            return $baseAmount * ((float) $rule->value / 100.0);
        }

        if ($type === 'flat_per_service') {
            return ((float) $rule->value) * max(1, $quantity);
        }

        if ($type === 'tiered') {
            $threshold = (float) ($rule->tier_threshold ?? 0);
            if ($threshold > 0 && $baseAmount >= $threshold) {
                return $baseAmount * ((float) $rule->value / 100.0);
            }
        }

        return 0.0;
    }

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
            $q = Invoice::query()->with(['branch', 'payments'])->latest();

            if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);

            if (!empty($data['from']) || !empty($data['to'])) {
                $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
                $to = !empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;
                if ($from && $to) $q->whereBetween('created_at', [$from, $to]);
                elseif ($from) $q->where('created_at', '>=', $from);
                elseif ($to) $q->where('created_at', '<=', $to);
            }

            $invoices = $q->paginate(50);

            // Transform to frontend-friendly "Transaction" shape
            $dataRows = collect($invoices->items())->map(function (Invoice $inv) {
                return [
                    'id'           => (string) $inv->id,
                    'tenant_id'    => (string) $inv->tenant_id,
                    'location_id'  => (string) $inv->branch_id,
                    'appointment_id' => $inv->appointment_id ? (string) $inv->appointment_id : null,
                    'total'        => (string) $inv->total,
                    'status'       => (string) $inv->status,
                    'Payments'     => $inv->payments->map(fn (Payment $p) => [
                        'id'            => (string) $p->id,
                        'transaction_id'=> (string) $inv->id,
                        'method'        => $p->method,
                        'amount'        => (string) $p->amount,
                        'reference'     => $p->reference,
                    ])->values(),
                    'Location'     => $inv->relationLoaded('branch') && $inv->branch ? [
                        'id'   => (string) $inv->branch->id,
                        'name' => $inv->branch->name,
                    ] : null,
                    'created_at'   => optional($inv->created_at)->toISOString(),
                ];
            })->values()->all();

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $dataRows,
                'meta'    => [
                    'current_page' => $invoices->currentPage(),
                    'per_page'     => $invoices->perPage(),
                    'total'        => $invoices->total(),
                    'last_page'    => $invoices->lastPage(),
                    'from'         => $invoices->firstItem(),
                    'to'           => $invoices->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Invoice $sale): JsonResponse
    {
        try {
            $sale->load(['branch', 'items', 'payments', 'customer']);

            $receipt = [
                'id' => (string) $sale->id,
                'total' => (string) $sale->total,
                'discount' => (string) $sale->discount,
                'Location' => $sale->branch ? ['name' => $sale->branch->name] : null,
                'Customer' => $sale->customer ? [
                    'id' => (string) $sale->customer->id,
                    'name' => (string) $sale->customer->name,
                    'email' => $sale->customer->email,
                    'phone' => $sale->customer->phone,
                ] : null,
                'TransactionItems' => $sale->items->map(function (InvoiceItem $it) {
                    $service = $it->itemable_type === Service::class ? $it->itemable : null;
                    $product = $it->itemable_type === Product::class ? $it->itemable : null;
                    return [
                        'quantity' => (string) $it->quantity,
                        'unit_price' => (string) $it->unit_price,
                        'total' => (string) $it->total,
                        'Service' => $service ? ['name' => $service->name] : null,
                        'Product' => $product ? ['name' => $product->name] : null,
                    ];
                })->values(),
                'Payments' => $sale->payments->map(fn (Payment $p) => [
                    'method' => $p->method,
                    'amount' => (string) $p->amount,
                ])->values(),
                'created_at' => optional($sale->created_at)->toISOString(),
            ];

            return $this->success($receipt);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request, PosCheckoutService $checkout): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id'   => 'required|exists:branches,id',
                'customer_id' => 'nullable|exists:customers,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'notes'       => 'nullable|string',
                'items'       => 'present|array',
                'items.*.service_id' => 'nullable|exists:services,id',
                'items.*.product_id' => 'nullable|exists:products,id',
                'items.*.quantity'   => 'required|numeric|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                // Optional Week-5 revenue classification
                'tips_amount' => 'nullable|numeric|min:0',
                'tip_allocation_mode' => 'nullable|in:single,equal_split,custom',
                'tip_allocations' => 'nullable|array',
                'tip_allocations.*.staff_id' => 'required_with:tip_allocations|exists:staff,id',
                'tip_allocations.*.amount' => 'nullable|numeric|min:0',
                'gift_card_amount' => 'nullable|numeric|min:0',
                'discount_type' => 'nullable|in:flat,percent',
                'discount_value' => 'nullable|numeric|min:0',
                'discount_code' => 'nullable|string|max:64',
                'payments'    => 'nullable|array',
                'payments.*.method' => 'required|string|in:cash,card,bank_transfer,wallet,whish,omt,transfer,mobile',
                'payments.*.amount' => 'required|numeric|min:0',
                'payments.*.reference' => 'nullable|string|max:255',
                // Package / Membership purchase
                'package_template_id'  => 'nullable|integer|exists:service_package_templates,id',
                'membership_plan_id'   => 'nullable|integer|exists:membership_plan_templates,id',
                // Legacy fallback (old clients)
                'payment_method' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            LedgerService::assertNotLocked((int) $tenantId, now()->toDateString());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            $invoice = $checkout->checkout($data, (int) $tenantId, (int) auth('api')->id());

            return $this->created([
                'id' => (string) $invoice->id,
                'tenant_id' => (string) $invoice->tenant_id,
                'location_id' => (string) $invoice->branch_id,
                'appointment_id' => $invoice->appointment_id ? (string) $invoice->appointment_id : null,
                'invoice_number' => $invoice->invoice_number,
                'total' => (string) $invoice->total,
                'status' => (string) $invoice->status,
                'customer' => $invoice->customer ? [
                    'id' => (string) $invoice->customer->id,
                    'name' => (string) $invoice->customer->name,
                    'email' => $invoice->customer->email,
                    'phone' => $invoice->customer->phone,
                ] : null,
                'Payments' => $invoice->payments->map(fn (Payment $p) => [
                    'id' => (string) $p->id,
                    'transaction_id' => (string) $invoice->id,
                    'method' => $p->method,
                    'amount' => (string) $p->amount,
                    'reference' => $p->reference,
                ])->values(),
                'Location' => $invoice->branch ? ['id' => (string) $invoice->branch->id, 'name' => $invoice->branch->name] : null,
                'created_at' => optional($invoice->created_at)->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function refund(Request $request, Invoice $sale, SaleRefundService $refunds): JsonResponse
    {
        try {
            $data = $request->validate([
                'refund_reason' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        $user = auth('api')->user();
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('receptionist')) {
            $req = ApprovalRequest::create([
                'tenant_id' => (int) $tenantId,
                'branch_id' => (int) $sale->branch_id,
                'entity_type' => 'sale',
                'entity_id' => (int) $sale->id,
                'requested_action' => 'refund',
                'requested_by' => (int) $user->id,
                'payload' => [
                    'refund_reason' => $data['refund_reason'] ?? null,
                ],
                'status' => ApprovalRequest::STATUS_PENDING,
                'expires_at' => now()->addDays(7),
            ]);

            return $this->success($req, 'Refund request submitted for approval', 202);
        }

        try {
            $refunds->refund($sale, (int) auth('api')->id(), (int) $tenantId, $data['refund_reason'] ?? null);
            return $this->success(null, 'Refund processed');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function notifyReceipt(Request $request, Invoice $sale): JsonResponse
    {
        try {
            $data = $request->validate([
                'channel' => 'required|in:email,sms',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $sale->load(['customer', 'branch', 'items', 'payments']);
        if (!$sale->customer) {
            return $this->error('No customer linked to this sale', 422);
        }

        if ($data['channel'] === 'email') {
            if (empty($sale->customer->email)) {
                return $this->error('Customer email not available', 422);
            }

            Mail::to($sale->customer->email)->queue(new SaleReceiptMail($sale));
            AuditLogger::log(auth('api')->id(), (int) $sale->tenant_id, 'sale.receipt_email_queued', [
                'invoice_id' => $sale->id,
                'customer_id' => $sale->customer_id,
            ]);

            return $this->success(['queued' => true], 'Receipt email queued');
        }

        if (empty($sale->customer->phone)) {
            return $this->error('Customer phone not available', 422);
        }

        $smsPreview = 'sms:' . $sale->customer->phone . '?body=' . rawurlencode(
            'Receipt #' . $sale->invoice_number . ' total ' . number_format((float) $sale->total, 2)
        );
        AuditLogger::log(auth('api')->id(), (int) $sale->tenant_id, 'sale.receipt_sms_preview', [
            'invoice_id' => $sale->id,
            'customer_id' => $sale->customer_id,
        ]);

        return $this->success(['sms_url' => $smsPreview], 'SMS link generated');
    }
}

