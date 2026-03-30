<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function index(Request $request)
    {
        $query = LedgerEntry::with('branch')->orderByDesc('entry_date')->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category')) {
            $query->where('category', 'like', '%' . $request->category . '%');
        }

        if ($request->filled('from')) {
            $query->whereDate('entry_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('entry_date', '<=', $request->to);
        }

        if ($request->filled('is_locked')) {
            $query->where('is_locked', filter_var($request->is_locked, FILTER_VALIDATE_BOOLEAN));
        }

        $entries = $query->paginate(50);

        $rows = collect($entries->items())->map(function (LedgerEntry $e) {
            return [
                'id'             => (string) $e->id,
                'branch_id'      => $e->branch_id ? (string) $e->branch_id : null,
                'branch_name'    => $e->relationLoaded('branch') && $e->branch ? $e->branch->name : null,
                'type'           => $e->type,
                'category'       => $e->category,
                'amount'         => (float) $e->amount,
                'tax_amount'     => (float) $e->tax_amount,
                'reference_type' => $e->reference_type,
                'reference_id'   => $e->reference_id ? (string) $e->reference_id : null,
                'description'    => $e->description,
                'entry_date'     => $e->entry_date?->format('Y-m-d'),
                'is_locked'      => (bool) $e->is_locked,
                'created_at'     => $e->created_at,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'per_page'     => $entries->perPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }
}
