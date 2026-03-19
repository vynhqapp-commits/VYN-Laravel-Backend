<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CommissionEntry;
use App\Models\Debt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $paymentMethods = ['cash', 'card', 'transfer'];

        Tenant::all()->each(function (Tenant $tenant) use ($paymentMethods) {
            $tenant->makeCurrent();

            $branch      = Branch::where('tenant_id', $tenant->id)->first();
            $staffList   = Staff::where('tenant_id', $tenant->id)->get();
            $appointments = Appointment::where('tenant_id', $tenant->id)
                ->where('status', 'completed')
                ->with('services.service', 'customer')
                ->get();

            foreach ($appointments as $index => $appointment) {
                $subtotal = $appointment->services->sum('price');
                $tax      = round($subtotal * 0.15, 2); // 15% VAT
                $total    = $subtotal + $tax;
                $isPartial = rand(0, 4) === 0; // 20% chance of partial payment
                $paidAmount = $isPartial ? round($total * 0.5, 2) : $total;

                $invoice = Invoice::create([
                    'tenant_id'      => $tenant->id,
                    'branch_id'      => $branch->id,
                    'customer_id'    => $appointment->customer_id,
                    'appointment_id' => $appointment->id,
                    'invoice_number' => 'INV-' . $tenant->id . '-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'subtotal'       => $subtotal,
                    'discount'       => 0,
                    'tax'            => $tax,
                    'total'          => $total,
                    'paid_amount'    => $paidAmount,
                    'status'         => $isPartial ? 'partial' : 'paid',
                ]);

                // Invoice items
                foreach ($appointment->services as $apptService) {
                    InvoiceItem::create([
                        'invoice_id'    => $invoice->id,
                        'itemable_type' => 'App\Models\Service',
                        'itemable_id'   => $apptService->service_id,
                        'name'          => $apptService->service->name,
                        'quantity'      => 1,
                        'unit_price'    => $apptService->price,
                        'discount'      => 0,
                        'total'         => $apptService->price,
                    ]);
                }

                // Payment
                Payment::create([
                    'tenant_id'  => $tenant->id,
                    'invoice_id' => $invoice->id,
                    'method'     => $paymentMethods[array_rand($paymentMethods)],
                    'amount'     => $paidAmount,
                    'status'     => 'completed',
                ]);

                // Debt if partial
                if ($isPartial) {
                    Debt::create([
                        'tenant_id'       => $tenant->id,
                        'customer_id'     => $appointment->customer_id,
                        'invoice_id'      => $invoice->id,
                        'original_amount' => $total,
                        'paid_amount'     => $paidAmount,
                        'remaining_amount'=> round($total - $paidAmount, 2),
                        'status'          => 'partial',
                        'due_date'        => now()->addDays(30),
                    ]);
                }

                // Commission entry (10% of subtotal)
                $staff = $staffList->random();
                CommissionEntry::create([
                    'tenant_id'         => $tenant->id,
                    'staff_id'          => $staff->id,
                    'invoice_id'        => $invoice->id,
                    'base_amount'       => $subtotal,
                    'commission_amount' => round($subtotal * 0.10, 2),
                    'tip_amount'        => rand(0, 1) ? rand(10, 50) : 0,
                    'status'            => 'pending',
                ]);

                // Ledger entry
                LedgerEntry::create([
                    'tenant_id'      => $tenant->id,
                    'branch_id'      => $branch->id,
                    'type'           => 'revenue',
                    'category'       => 'service',
                    'amount'         => $subtotal,
                    'tax_amount'     => $tax,
                    'reference_type' => 'App\Models\Invoice',
                    'reference_id'   => $invoice->id,
                    'description'    => 'Invoice ' . $invoice->invoice_number,
                    'entry_date'     => now()->subDays(rand(0, 30)),
                    'is_locked'      => false,
                ]);
            }

            Tenant::forgetCurrent();
        });
    }
}
