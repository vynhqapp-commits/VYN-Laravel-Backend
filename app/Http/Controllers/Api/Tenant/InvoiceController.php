<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private function invoiceResource(Invoice $invoice, bool $withRelations = false): array
    {
        $data = [
            'id'             => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'branch_id'      => $invoice->branch_id ? (string) $invoice->branch_id : null,
            'customer_id'    => $invoice->customer_id ? (string) $invoice->customer_id : null,
            'appointment_id' => $invoice->appointment_id ? (string) $invoice->appointment_id : null,
            'subtotal'       => (float) $invoice->subtotal,
            'discount'       => (float) $invoice->discount,
            'tax'            => (float) $invoice->tax,
            'total'          => (float) $invoice->total,
            'paid_amount'    => (float) $invoice->paid_amount,
            'status'         => $invoice->status,
            'notes'          => $invoice->notes,
            'created_at'     => $invoice->created_at,
            'updated_at'     => $invoice->updated_at,
        ];

        if ($withRelations) {
            $data['customer'] = $invoice->relationLoaded('customer') ? $invoice->customer : null;
            $data['branch']   = $invoice->relationLoaded('branch')   ? $invoice->branch   : null;
            $data['items']    = $invoice->relationLoaded('items')
                ? $invoice->items->map(function ($item) {
                    $itemable = $item->relationLoaded('itemable') ? $item->itemable : null;
                    return [
                        'id'          => (string) $item->id,
                        'item_type'   => $item->itemable_type ? class_basename($item->itemable_type) : null,
                        'name'        => $item->name,
                        'description' => $itemable->description ?? null,
                        'quantity'    => (int) $item->quantity,
                        'unit_price'  => (float) $item->unit_price,
                        'discount'    => (float) $item->discount,
                        'total'       => (float) $item->total,
                    ];
                })->values()
                : [];
            $data['payments'] = $invoice->relationLoaded('payments')  ? $invoice->payments : [];
        } else {
            $data['customer'] = $invoice->relationLoaded('customer') ? [
                'id'   => (string) $invoice->customer->id,
                'name' => $invoice->customer->name,
            ] : null;
        }

        return $data;
    }

    public function index(Request $request)
    {
        $query = Invoice::with('customer');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $invoices = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($invoices->items())->map(fn($i) => $this->invoiceResource($i)),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'per_page'     => $invoices->perPage(),
                'total'        => $invoices->total(),
            ],
        ]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('customer', 'branch', 'items.itemable', 'payments');

        return response()->json([
            'data' => $this->invoiceResource($invoice, true),
        ]);
    }

    public function void(Invoice $invoice)
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Invoice is already voided.'], 422);
        }

        $invoice->update(['status' => 'void']);

        return response()->json([
            'data' => $this->invoiceResource($invoice->fresh()),
        ]);
    }
}
