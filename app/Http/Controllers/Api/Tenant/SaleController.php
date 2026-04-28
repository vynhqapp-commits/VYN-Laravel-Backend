<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\IndexSalesRequest;
use App\Http\Requests\Api\Tenant\NotifySaleReceiptRequest;
use App\Http\Requests\Api\Tenant\RefundSaleRequest;
use App\Http\Requests\Api\Tenant\StoreSaleRequest;
use App\Mail\SaleReceiptMail;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Services\PosCheckoutService;
use App\Services\SaleRefundService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

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
    public function __construct(
        private readonly PosCheckoutService $posCheckout,
        private readonly SaleRefundService $saleRefund,
    ) {}

    public function index(IndexSalesRequest $request): JsonResponse
    {
        $data = $request->validated();

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

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        try {
            LedgerService::assertNotLocked((int) $tenantId, now()->toDateString());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            $invoice = $this->posCheckout->checkout($data, (int) $tenantId, (int) auth('api')->id());

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

    public function refund(RefundSaleRequest $request, Invoice $sale): JsonResponse
    {
        $data = $request->validated();

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
            $this->saleRefund->refund($sale, (int) auth('api')->id(), (int) $tenantId, $data['refund_reason'] ?? null);
            return $this->success(null, 'Refund processed');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function notifyReceipt(NotifySaleReceiptRequest $request, Invoice $sale): JsonResponse
    {
        $data = $request->validated();

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

