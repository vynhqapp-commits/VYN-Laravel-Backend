<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\CashDrawer;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\TipAllocation;
use App\Models\DebtLedgerEntry;
use App\Models\CustomerServicePackage;
use App\Mail\SaleReceiptMail;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
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

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id'   => 'required|exists:branches,id',
                'customer_id' => 'nullable|exists:customers,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'notes'       => 'nullable|string',
                'items'       => 'required|array|min:1',
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
                // Legacy fallback (old clients)
                'payment_method' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

        // Soft-lock guard: reject entries into a closed period
        try {
            LedgerService::assertNotLocked((int) $tenantId, now()->toDateString());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $tenant = Tenant::query()->find($tenantId);
        $vatRate = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        DB::beginTransaction();
        try {
            $appointment = null;
            if (!empty($data['appointment_id'])) {
                $appointment = Appointment::query()->findOrFail($data['appointment_id']);
                if ((int) $appointment->tenant_id !== (int) $tenantId) {
                    return $this->error('Invalid appointment for tenant', 422);
                }
                if ((int) $appointment->branch_id !== (int) $data['branch_id']) {
                    return $this->error('Appointment branch mismatch', 422);
                }
                // If appointment has a customer, enforce it matches the invoice customer (or set it)
                if (!empty($appointment->customer_id) && !empty($data['customer_id']) && (int) $appointment->customer_id !== (int) $data['customer_id']) {
                    return $this->error('Appointment customer mismatch', 422);
                }
                if (empty($data['customer_id']) && !empty($appointment->customer_id)) {
                    $data['customer_id'] = $appointment->customer_id;
                }
            }

            $subtotal = 0;
            foreach ($data['items'] as $it) {
                $qty = (float) $it['quantity'];
                $unit = (float) $it['unit_price'];
                $subtotal += $qty * $unit;
            }

            $tipsAmount = isset($data['tips_amount']) ? (float) $data['tips_amount'] : 0.0;
            $giftCardAmount = isset($data['gift_card_amount']) ? (float) $data['gift_card_amount'] : 0.0;
            $discountType = $data['discount_type'] ?? null;
            $discountValue = isset($data['discount_value']) ? (float) $data['discount_value'] : 0.0;
            $discountAmount = 0.0;
            $couponId = null;
            $discountCode = !empty($data['discount_code']) ? strtoupper(trim((string) $data['discount_code'])) : null;

            if ($discountCode) {
                /** @var Coupon|null $coupon */
                $coupon = Coupon::query()
                    ->where('code', $discountCode)
                    ->where('is_active', true)
                    ->first();

                $now = now();
                $invalid = false;
                if (!$coupon) $invalid = true;
                if ($coupon && $coupon->starts_at && $now->lt($coupon->starts_at)) $invalid = true;
                if ($coupon && $coupon->ends_at && $now->gt($coupon->ends_at)) $invalid = true;
                if ($coupon && $coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) $invalid = true;
                if ($coupon && $coupon->min_subtotal !== null && (float) $subtotal + 0.0001 < (float) $coupon->min_subtotal) $invalid = true;

                if ($invalid) {
                    return $this->validationError(['discount_code' => ['Invalid or expired coupon code.']]);
                }

                $couponId = $coupon->id;
                if ($coupon->type === 'percent') {
                    $discountAmount = max(0.0, min($subtotal, ($subtotal * (float) $coupon->value) / 100.0));
                } else {
                    $discountAmount = max(0.0, min($subtotal, (float) $coupon->value));
                }
            } else {
                if ($discountType === 'percent') {
                    $discountAmount = max(0.0, min($subtotal, ($subtotal * $discountValue) / 100.0));
                } elseif ($discountType === 'flat') {
                    $discountAmount = max(0.0, min($subtotal, $discountValue));
                }
            }
            $invoiceTotal = max(0.0, $subtotal - $discountAmount + $tipsAmount + $giftCardAmount);

            $invoice = Invoice::create([
                'tenant_id'      => $tenantId,
                'branch_id'      => $data['branch_id'],
                'customer_id'    => $data['customer_id'] ?? null,
                'appointment_id' => $data['appointment_id'] ?? null,
                'coupon_id'      => $couponId,
                'discount_code'  => $discountCode,
                'invoice_number' => 'INV-' . now()->format('Ymd') . '-' . uniqid(),
                'subtotal'       => $subtotal,
                'discount'       => $discountAmount,
                'tax'            => 0,
                'total'          => $invoiceTotal,
                'paid_amount'    => 0,
                'status'         => 'draft',
                'notes'          => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);

            foreach ($data['items'] as $it) {
                $serviceId = $it['service_id'] ?? null;
                $productId = $it['product_id'] ?? null;
                $qty = (int) $it['quantity'];
                $unit = (float) $it['unit_price'];

                if (!$serviceId && !$productId) {
                    throw new \RuntimeException('Each item must have service_id or product_id');
                }

                $itemable = $serviceId ? Service::findOrFail($serviceId) : Product::findOrFail($productId);
                $type = $serviceId ? Service::class : Product::class;

                InvoiceItem::create([
                    'invoice_id'     => $invoice->id,
                    'itemable_type'  => $type,
                    'itemable_id'    => $itemable->id,
                    'name'           => $itemable->name,
                    'quantity'       => $qty,
                    'unit_price'     => $unit,
                    'discount'       => 0,
                    'total'          => $qty * $unit,
                ]);
            }

            $paymentRows = $data['payments'] ?? null;
            if (!$paymentRows && !empty($data['payment_method'])) {
                // Legacy: treat as full cash/card/etc payment
                $paymentRows = [
                    ['method' => $data['payment_method'], 'amount' => (float) $invoice->total],
                ];
            }
            $paymentRows = $paymentRows ?? [];

            $paid = 0;
            foreach ($paymentRows as $p) {
                $amt = (float) $p['amount'];
                if ($amt <= 0) continue;
                $paid += $amt;

                $rawMethod = strtolower(str_replace(' ', '_', trim((string) $p['method'])));
                $methodMap = [
                    'cash' => 'cash',
                    'card' => 'card',
                    'bank' => 'bank_transfer',
                    'bank_transfer' => 'bank_transfer',
                    'wallet' => 'wallet',
                    'whish' => 'whish',
                    'omt' => 'omt',
                    'transfer' => 'bank_transfer',
                    'mobile' => 'wallet',
                ];
                $method = $methodMap[$rawMethod] ?? null;
                if ($method === null) {
                    throw new \RuntimeException('Unsupported payment method: ' . $rawMethod);
                }
                $cashDrawerSessionId = null;
                if ($method === 'cash') {
                    $drawer = CashDrawer::query()->where('branch_id', $data['branch_id'])->first();
                    $openSession = $drawer?->sessions()->where('status', 'open')->first();
                    $cashDrawerSessionId = $openSession?->id;
                }

                Payment::create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'method' => $method,
                    'amount' => $amt,
                    'reference' => $p['reference'] ?? null,
                    'status' => 'completed',
                    'cash_drawer_session_id' => $cashDrawerSessionId,
                ]);
            }

            if (count($paymentRows) > 1) {
                AuditLogger::log(auth('api')->id(), $tenantId, 'sale.split_payment', [
                    'invoice_id' => $invoice->id,
                    'payment_count' => count($paymentRows),
                    'methods' => collect($paymentRows)->pluck('method')->values()->all(),
                ]);
            }

            if ($paid > (float) $invoice->total + 0.01) {
                throw new \RuntimeException('Payment total cannot exceed the order total');
            }

            $status = 'paid';
            if ($paid + 0.01 < (float) $invoice->total) $status = 'partial';

            $invoice->update([
                'paid_amount' => $paid,
                'status' => $status,
            ]);

            // Coupon usage tracking (counts on successful sale creation)
            if (!empty($couponId)) {
                Coupon::query()->where('id', $couponId)->increment('used_count');
            }

            // Revenue classification (services vs products vs tips vs gift cards) into ledger
            $invoiceItems = InvoiceItem::where('invoice_id', $invoice->id)->get(['itemable_type', 'total', 'quantity']);
            $servicesRevenue = (float) $invoiceItems->where('itemable_type', Service::class)->sum('total');
            $productsRevenue = (float) $invoiceItems->where('itemable_type', Product::class)->sum('total');
            $serviceQty = (int) $invoiceItems->where('itemable_type', Service::class)->sum('quantity');

            $ledgerRows = [
                ['category' => 'services', 'amount' => $servicesRevenue],
                ['category' => 'products', 'amount' => $productsRevenue],
                ['category' => 'tips', 'amount' => $tipsAmount],
                // Week 5: issuance treated as revenue category (redemption handled later in Gift Cards module)
                ['category' => 'gift_cards', 'amount' => $giftCardAmount],
            ];
            foreach ($ledgerRows as $row) {
                if (abs((float) $row['amount']) < 0.0001) continue;
                $taxAmount = 0.0;
                if ($vatRate !== null && $vatRate > 0) {
                    $taxAmount = ((float) $row['amount']) * ($vatRate / 100.0);
                }
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $invoice->branch_id,
                    'type' => 'revenue',
                    'category' => 'pos_' . $row['category'],
                    'amount' => (float) $row['amount'],
                    'tax_amount' => $taxAmount,
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                    'description' => 'POS sale (' . $row['category'] . ')',
                    'entry_date' => now()->toDateString(),
                    'is_locked' => false,
                ]);
            }

            // Create debt when partial
            if ($status === 'partial' && !empty($invoice->customer_id)) {
                $remaining = (float) $invoice->total - $paid;
                $debt = Debt::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $invoice->customer_id,
                    'invoice_id' => $invoice->id,
                    'original_amount' => (float) $invoice->total,
                    'paid_amount' => $paid,
                    'remaining_amount' => $remaining,
                    'status' => 'open',
                    'due_date' => now()->addDays(30)->toDateString(),
                ]);

                // Append-only debt ledger: initial charge
                DebtLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $invoice->customer_id,
                    'debt_id' => $debt->id,
                    'invoice_id' => $invoice->id,
                    'type' => 'charge',
                    'amount' => $remaining,
                    'balance_after' => $remaining,
                    'notes' => 'Debt created from partial sale',
                    'created_by' => auth('api')->id(),
                ]);
            }

            // Booking integration: mark appointment completed when fully paid
            if ($appointment) {
                if ($status === 'paid') {
                    $appointment->update(['status' => 'completed']);

                    // Auto-consumption of customer service packages based on service units sold.
                    // Consumes "latest expires_at first" (non-null first).
                    $customerId = $appointment->customer_id;
                    if (!empty($customerId) && $serviceQty > 0) {
                        $today = Carbon::today();
                        $remainingToConsume = $serviceQty;

                        $packages = CustomerServicePackage::query()
                            ->where('customer_id', $customerId)
                            ->where('remaining_services', '>', 0)
                            ->where('status', 'active')
                            ->where(function ($q) use ($today) {
                                $q->whereNull('expires_at')
                                    ->orWhereDate('expires_at', '>=', $today);
                            })
                            ->orderByRaw('expires_at IS NULL ASC')
                            ->orderByDesc('expires_at')
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->get();

                        foreach ($packages as $pkg) {
                            if ($remainingToConsume <= 0) break;

                            $take = min($remainingToConsume, (int) $pkg->remaining_services);
                            if ($take <= 0) continue;

                            $pkg->remaining_services = (int) $pkg->remaining_services - $take;
                            if ($pkg->remaining_services <= 0) {
                                $pkg->remaining_services = 0;
                                $pkg->status = 'exhausted';
                            }
                            $pkg->save();

                            $remainingToConsume -= $take;
                        }
                    }
                }
            }

            // Commission + tips allocation (services only; tips separate) when fully paid
            if ($status === 'paid' && $appointment && !empty($appointment->staff_id)) {
                $staffId = (int) $appointment->staff_id;
                $serviceBase = $servicesRevenue;

                $rule = CommissionRule::query()
                    ->where('is_active', true)
                    ->where(function ($q) use ($staffId) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
                    })
                    ->whereNull('service_id') // services-only aggregate for now
                    ->orderByRaw('CASE WHEN staff_id IS NULL THEN 1 ELSE 0 END') // prefer staff-specific
                    ->first();

                $commissionAmt = 0.0;
                if ($rule && $serviceBase > 0) {
                    if ($rule->type === 'percentage') {
                        $commissionAmt = ((float) $serviceBase) * ((float) $rule->value / 100.0);
                    } elseif ($rule->type === 'fixed') {
                        $commissionAmt = (float) $rule->value;
                    } elseif ($rule->type === 'tiered') {
                        $threshold = (float) ($rule->tier_threshold ?? 0);
                        if ((float) $serviceBase >= $threshold && $threshold > 0) {
                            $commissionAmt = ((float) $serviceBase) * ((float) $rule->value / 100.0);
                        }
                    }
                }

                if ($commissionAmt > 0.009) {
                    $ce = CommissionEntry::create([
                        'tenant_id' => $tenantId,
                        'staff_id' => $staffId,
                        'invoice_id' => $invoice->id,
                        'commission_rule_id' => $rule?->id,
                        'base_amount' => (float) $serviceBase,
                        'commission_amount' => (float) $commissionAmt,
                        'tip_amount' => 0,
                        'status' => 'pending',
                    ]);

                    // ledger expense for commission earned
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $invoice->branch_id,
                        'type' => 'expense',
                        'category' => 'staff_commission',
                        'amount' => (float) $commissionAmt,
                        'tax_amount' => 0,
                        'reference_type' => CommissionEntry::class,
                        'reference_id' => $ce->id,
                        'description' => 'Staff commission earned',
                        'entry_date' => now()->toDateString(),
                        'is_locked' => false,
                    ]);
                }

                if ($tipsAmount > 0.009) {
                    $mode = $data['tip_allocation_mode'] ?? 'single';
                    $tipRows = collect($data['tip_allocations'] ?? [])->map(function ($r) {
                        return [
                            'staff_id' => (int) ($r['staff_id'] ?? 0),
                            'amount' => isset($r['amount']) ? (float) $r['amount'] : null,
                        ];
                    })->filter(fn ($r) => $r['staff_id'] > 0)->values();

                    $allocations = collect();
                    if ($mode === 'custom') {
                        if ($tipRows->isEmpty()) {
                            throw new \RuntimeException('Custom tip split requires at least one staff allocation');
                        }
                        $sum = (float) $tipRows->sum(fn ($r) => (float) ($r['amount'] ?? 0));
                        if (abs($sum - $tipsAmount) > 0.01) {
                            throw new \RuntimeException('Custom tip split total must equal tips amount');
                        }
                        $allocations = $tipRows->map(fn ($r) => [
                            'staff_id' => (int) $r['staff_id'],
                            'amount' => (float) $r['amount'],
                        ]);
                    } elseif ($mode === 'equal_split') {
                        if ($tipRows->isEmpty()) {
                            throw new \RuntimeException('Equal split requires selected staff');
                        }
                        $n = $tipRows->count();
                        $base = floor((($tipsAmount / $n) * 100)) / 100;
                        $remaining = round($tipsAmount - ($base * $n), 2);
                        $allocations = $tipRows->values()->map(function ($r, $idx) use ($base, $remaining) {
                            return [
                                'staff_id' => (int) $r['staff_id'],
                                'amount' => $idx === 0 ? (float) round($base + $remaining, 2) : (float) $base,
                            ];
                        });
                    } else {
                        $singleStaffId = !empty($appointment->staff_id)
                            ? (int) $appointment->staff_id
                            : (int) ($tipRows->first()['staff_id'] ?? 0);
                        if ($singleStaffId <= 0) {
                            throw new \RuntimeException('No staff available for tip allocation');
                        }
                        $allocations = collect([[
                            'staff_id' => $singleStaffId,
                            'amount' => (float) $tipsAmount,
                        ]]);
                    }

                    foreach ($allocations as $a) {
                        $staffExists = Staff::query()
                            ->where('id', (int) $a['staff_id'])
                            ->where('tenant_id', $tenantId)
                            ->exists();
                        if (!$staffExists) {
                            throw new \RuntimeException('Invalid tip staff allocation');
                        }

                        $tip = TipAllocation::create([
                            'tenant_id' => $tenantId,
                            'staff_id' => (int) $a['staff_id'],
                            'invoice_id' => $invoice->id,
                            'amount' => (float) $a['amount'],
                            'earned_at' => now(),
                        ]);

                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'branch_id' => $invoice->branch_id,
                            'type' => 'expense',
                            'category' => 'staff_tips',
                            'amount' => (float) $a['amount'],
                            'tax_amount' => 0,
                            'reference_type' => TipAllocation::class,
                            'reference_id' => $tip->id,
                            'description' => 'Tips allocated to staff',
                            'entry_date' => now()->toDateString(),
                            'is_locked' => false,
                        ]);
                    }
                }
            }

            DB::commit();

            $invoice->load(['branch', 'payments', 'customer']);

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
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function refund(Request $request, Invoice $sale): JsonResponse
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

        $tenant = Tenant::query()->find($tenantId);
        $vatRate = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        DB::beginTransaction();
        try {
            $sale->load(['payments', 'debt', 'items']);

            if (in_array($sale->status, ['refunded', 'void'], true)) {
                return $this->error('Sale already refunded', 422);
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

                // Append-only debt reversal entry
                DebtLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $sale->debt->customer_id,
                    'debt_id' => $sale->debt->id,
                    'invoice_id' => $sale->id,
                    'type' => 'refund_reversal',
                    'amount' => $debtRemaining * -1,
                    'balance_after' => 0,
                    'notes' => 'Debt reversed due to refund',
                    'created_by' => auth('api')->id(),
                ]);
            }

            $sale->update([
                'status' => 'refunded',
                'paid_amount' => 0,
            ]);

            // Reverse commissions (if any)
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

            // Refund classification (negative) into ledger
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
                    'description' => $data['refund_reason'] ?? ('Refund (' . $row['category'] . ')'),
                    'entry_date' => now()->toDateString(),
                    'is_locked' => false,
                ]);
            }

            DB::commit();

            AuditLogger::log(auth('api')->id(), $tenantId, 'sale.refunded', [
                'invoice_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'reason' => $data['refund_reason'] ?? null,
            ]);

            return $this->success(null, 'Refund processed');
        } catch (\Throwable $e) {
            DB::rollBack();
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

