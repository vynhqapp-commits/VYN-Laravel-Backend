<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Coupon;
use App\Models\CustomerMembership;
use App\Models\CustomerServicePackage;
use App\Models\Debt;
use App\Models\DebtLedgerEntry;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\MembershipPlanTemplate;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackageTemplate;
use App\Models\Staff;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TipAllocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PosCheckoutService
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

    /**
     * Create a sale (invoice + related side-effects) and return the persisted invoice.
     *
     * Expects request validation to be done in controller.
     */
    public function checkout(array $data, int $tenantId, int $actorUserId): Invoice
    {
        $tenant = Tenant::query()->find($tenantId);
        $vatRate = $tenant?->vat_rate !== null ? (float) $tenant->vat_rate : null;

        DB::beginTransaction();
        try {
            $appointment = null;
            if (!empty($data['appointment_id'])) {
                $appointment = Appointment::query()->findOrFail($data['appointment_id']);
                if ((int) $appointment->tenant_id !== (int) $tenantId) {
                    throw new \RuntimeException('Invalid appointment for tenant');
                }
                if ((int) $appointment->branch_id !== (int) $data['branch_id']) {
                    throw new \RuntimeException('Appointment branch mismatch');
                }
                if (!empty($appointment->customer_id) && !empty($data['customer_id']) && (int) $appointment->customer_id !== (int) $data['customer_id']) {
                    throw new \RuntimeException('Appointment customer mismatch');
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

            $packageTemplate = null;
            $membershipPlan = null;
            if (!empty($data['package_template_id'])) {
                $packageTemplate = ServicePackageTemplate::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $data['package_template_id'])
                    ->first();
                if ($packageTemplate) {
                    $subtotal += (float) $packageTemplate->price;
                }
            }
            if (!empty($data['membership_plan_id'])) {
                $membershipPlan = MembershipPlanTemplate::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $data['membership_plan_id'])
                    ->first();
                if ($membershipPlan) {
                    $subtotal += (float) $membershipPlan->price;
                }
            }

            if (empty($data['items']) && !$packageTemplate && !$membershipPlan) {
                throw new \RuntimeException('At least one item, package, or membership is required.');
            }

            $tipsAmount = isset($data['tips_amount']) ? (float) $data['tips_amount'] : 0.0;
            $giftCardAmount = isset($data['gift_card_amount']) ? (float) $data['gift_card_amount'] : 0.0;
            $discountType = $data['discount_type'] ?? null;
            $discountValue = isset($data['discount_value']) ? (float) $data['discount_value'] : 0.0;
            $discountAmount = 0.0;
            $couponId = null;
            $discountCode = !empty($data['discount_code']) ? strtoupper(trim((string) $data['discount_code'])) : null;

            if ($discountCode) {
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
                    throw new \RuntimeException('Invalid or expired coupon code.');
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

            if ($packageTemplate) {
                InvoiceItem::create([
                    'invoice_id'    => $invoice->id,
                    'itemable_type' => ServicePackageTemplate::class,
                    'itemable_id'   => $packageTemplate->id,
                    'name'          => $packageTemplate->name . ' (Package)',
                    'quantity'      => 1,
                    'unit_price'    => (float) $packageTemplate->price,
                    'discount'      => 0,
                    'total'         => (float) $packageTemplate->price,
                ]);
            }
            if ($membershipPlan) {
                InvoiceItem::create([
                    'invoice_id'    => $invoice->id,
                    'itemable_type' => MembershipPlanTemplate::class,
                    'itemable_id'   => $membershipPlan->id,
                    'name'          => $membershipPlan->name . ' (Membership)',
                    'quantity'      => 1,
                    'unit_price'    => (float) $membershipPlan->price,
                    'discount'      => 0,
                    'total'         => (float) $membershipPlan->price,
                ]);
            }

            foreach ($data['items'] as $it) {
                $productId = $it['product_id'] ?? null;
                if (!$productId) continue;

                $qty = (int) $it['quantity'];
                if ($qty <= 0) continue;

                $productRow = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $productId)
                    ->first();
                if (!$productRow) {
                    throw new \RuntimeException('Invalid product for tenant');
                }

                $inv = Inventory::query()->firstOrCreate([
                    'tenant_id' => $tenantId,
                    'branch_id' => (int) $data['branch_id'],
                    'product_id' => (int) $productId,
                ], ['quantity' => 0]);

                $newQty = (int) $inv->quantity - $qty;
                if ($newQty < 0) {
                    throw new \RuntimeException('Insufficient stock for product: ' . $productRow->name);
                }
                $inv->update(['quantity' => $newQty]);

                StockMovement::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => (int) $data['branch_id'],
                    'product_id' => (int) $productId,
                    'type' => 'sold',
                    'quantity' => $qty,
                    'reason' => 'pos_sale',
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                ]);
            }

            $paymentRows = $data['payments'] ?? null;
            if (!$paymentRows && !empty($data['payment_method'])) {
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
                    if (!$openSession) {
                        throw new \RuntimeException('Cash drawer session must be open before accepting cash payments');
                    }
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

                if ($method === 'cash' && $cashDrawerSessionId !== null) {
                    CashMovement::create([
                        'tenant_id' => $tenantId,
                        'cash_drawer_session_id' => $cashDrawerSessionId,
                        'type' => 'cash_in',
                        'amount' => $amt,
                        'reason' => 'Checkout — ' . $invoice->invoice_number,
                        'created_by' => $actorUserId,
                    ]);
                }
            }

            if (count($paymentRows) > 1) {
                AuditLogger::log($actorUserId, $tenantId, 'sale.split_payment', [
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

            if (!empty($couponId)) {
                Coupon::query()->where('id', $couponId)->increment('used_count');
            }

            $invoiceItems = InvoiceItem::where('invoice_id', $invoice->id)->get(['itemable_type', 'total', 'quantity']);
            $servicesRevenue = (float) $invoiceItems->where('itemable_type', Service::class)->sum('total');
            $productsRevenue = (float) $invoiceItems->where('itemable_type', Product::class)->sum('total');
            $serviceQty = (int) $invoiceItems->where('itemable_type', Service::class)->sum('quantity');

            $ledgerRows = [
                ['category' => 'services', 'amount' => $servicesRevenue],
                ['category' => 'products', 'amount' => $productsRevenue],
                ['category' => 'tips', 'amount' => $tipsAmount],
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

                DebtLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $invoice->customer_id,
                    'debt_id' => $debt->id,
                    'invoice_id' => $invoice->id,
                    'type' => 'charge',
                    'amount' => $remaining,
                    'balance_after' => $remaining,
                    'notes' => 'Debt created from partial sale',
                    'created_by' => $actorUserId,
                ]);
            }

            if ($appointment) {
                if ($status === 'paid') {
                    $appointment->update(['status' => 'completed']);

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

            if ($status === 'paid' && $appointment && !empty($appointment->staff_id)) {
                $staffId = (int) $appointment->staff_id;
                $rules = CommissionRule::query()
                    ->where('is_active', true)
                    ->where(function ($q) use ($staffId) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
                    })
                    ->get();

                $serviceItems = InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('itemable_type', Service::class)
                    ->get(['itemable_id', 'total', 'quantity']);

                foreach ($serviceItems as $serviceItem) {
                    $serviceId = (int) $serviceItem->itemable_id;
                    $serviceBase = (float) $serviceItem->total;
                    $serviceQtyLine = (int) $serviceItem->quantity;
                    $rule = $this->resolveCommissionRule(
                        $rules,
                        $staffId,
                        $serviceId,
                        ['percent_service', 'flat_per_service', 'tiered']
                    ) ?? $this->resolveCommissionRule(
                        $rules,
                        $staffId,
                        null,
                        ['percent_service', 'flat_per_service', 'tiered']
                    );

                    if (!$rule) continue;

                    $commissionAmt = $this->calculateCommissionAmount($rule, $serviceBase, $serviceQtyLine);
                    if ($commissionAmt <= 0.009) continue;

                    $ce = CommissionEntry::create([
                        'tenant_id' => $tenantId,
                        'staff_id' => $staffId,
                        'invoice_id' => $invoice->id,
                        'commission_rule_id' => $rule->id,
                        'base_amount' => (float) $serviceBase,
                        'commission_amount' => (float) $commissionAmt,
                        'tip_amount' => 0,
                        'status' => 'pending',
                    ]);

                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $invoice->branch_id,
                        'type' => 'expense',
                        'category' => 'staff_commission',
                        'amount' => (float) $commissionAmt,
                        'tax_amount' => 0,
                        'reference_type' => CommissionEntry::class,
                        'reference_id' => $ce->id,
                        'description' => 'Staff commission earned (service)',
                        'entry_date' => now()->toDateString(),
                        'is_locked' => false,
                    ]);
                }

                if ($productsRevenue > 0) {
                    $productRule = $this->resolveCommissionRule($rules, $staffId, null, ['percent_product']);
                    if ($productRule) {
                        $productCommission = $this->calculateCommissionAmount($productRule, (float) $productsRevenue, 1);
                        if ($productCommission > 0.009) {
                            $ce = CommissionEntry::create([
                                'tenant_id' => $tenantId,
                                'staff_id' => $staffId,
                                'invoice_id' => $invoice->id,
                                'commission_rule_id' => $productRule->id,
                                'base_amount' => (float) $productsRevenue,
                                'commission_amount' => (float) $productCommission,
                                'tip_amount' => 0,
                                'status' => 'pending',
                            ]);

                            LedgerEntry::create([
                                'tenant_id' => $tenantId,
                                'branch_id' => $invoice->branch_id,
                                'type' => 'expense',
                                'category' => 'staff_commission',
                                'amount' => (float) $productCommission,
                                'tax_amount' => 0,
                                'reference_type' => CommissionEntry::class,
                                'reference_id' => $ce->id,
                                'description' => 'Staff commission earned (product)',
                                'entry_date' => now()->toDateString(),
                                'is_locked' => false,
                            ]);
                        }
                    }
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
                        if ($tipRows->isEmpty()) throw new \RuntimeException('Custom tip split requires at least one staff allocation');
                        $sum = (float) $tipRows->sum(fn ($r) => (float) ($r['amount'] ?? 0));
                        if (abs($sum - $tipsAmount) > 0.01) throw new \RuntimeException('Custom tip split total must equal tips amount');
                        $allocations = $tipRows->map(fn ($r) => [
                            'staff_id' => (int) $r['staff_id'],
                            'amount' => (float) $r['amount'],
                        ]);
                    } elseif ($mode === 'equal_split') {
                        if ($tipRows->isEmpty()) throw new \RuntimeException('Equal split requires selected staff');
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
                        $singleStaffId = !empty($appointment?->staff_id)
                            ? (int) $appointment->staff_id
                            : (int) ($tipRows->first()['staff_id'] ?? 0);
                        if ($singleStaffId <= 0) throw new \RuntimeException('No staff available for tip allocation');
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
                        if (!$staffExists) throw new \RuntimeException('Invalid tip staff allocation');

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

            if ($packageTemplate && !empty($data['customer_id'])) {
                CustomerServicePackage::create([
                    'tenant_id'          => $tenantId,
                    'customer_id'        => $data['customer_id'],
                    'name'               => $packageTemplate->name,
                    'total_services'     => $packageTemplate->total_sessions,
                    'remaining_services' => $packageTemplate->total_sessions,
                    'expires_at'         => $packageTemplate->validity_days
                        ? Carbon::today()->addDays($packageTemplate->validity_days)
                        : null,
                    'status'             => 'active',
                ]);
            }

            if ($membershipPlan && !empty($data['customer_id'])) {
                $startDate = Carbon::today();
                $renewalDate = $startDate->copy()->addMonths($membershipPlan->interval_months);
                CustomerMembership::create([
                    'tenant_id'                  => $tenantId,
                    'customer_id'                => $data['customer_id'],
                    'name'                       => $membershipPlan->name,
                    'plan'                       => $membershipPlan->description,
                    'start_date'                 => $startDate,
                    'renewal_date'               => $renewalDate,
                    'interval_months'            => $membershipPlan->interval_months,
                    'service_credits_per_renewal' => $membershipPlan->credits_per_renewal,
                    'remaining_services'         => $membershipPlan->credits_per_renewal,
                    'status'                     => 'active',
                ]);
            }

            DB::commit();

            return $invoice->fresh()->load(['branch', 'payments', 'customer']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

