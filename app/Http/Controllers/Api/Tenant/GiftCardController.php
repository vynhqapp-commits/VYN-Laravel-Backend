<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GiftCardController extends Controller
{
    private function cardResource(GiftCard $card): array
    {
        return [
            'id'                => $card->id,
            'code'              => $card->code,
            'initial_balance'   => (float) $card->initial_balance,
            'remaining_balance' => (float) $card->remaining_balance,
            'current_balance'   => (float) $card->remaining_balance,
            'currency'          => $card->currency ?? 'USD',
            'status'            => $card->status,
            'expires_at'        => $card->expires_at?->format('Y-m-d'),
            'customer_id'       => $card->customer_id,
            'customer'          => $card->relationLoaded('customer') ? $card->customer : null,
            'created_at'        => $card->created_at,
            'updated_at'        => $card->updated_at,
        ];
    }

    public function index(Request $request)
    {
        $query = GiftCard::query()->with('customer');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%");
            });
        }

        $cards = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($cards->items())->map(fn($c) => $this->cardResource($c)),
            'meta' => [
                'current_page' => $cards->currentPage(),
                'last_page'    => $cards->lastPage(),
                'per_page'     => $cards->perPage(),
                'total'        => $cards->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'initial_balance' => 'required|numeric|min:0.01',
            'currency'        => 'nullable|string|size:3',
            'expires_at'      => 'nullable|date|after:today',
            'code'            => 'nullable|string|max:64|unique:gift_cards,code',
            'customer_id'     => 'nullable|exists:customers,id',
        ]);

        $card = GiftCard::create([
            'code'              => $data['code'] ?? strtoupper(Str::random(4) . '-' . Str::random(4)),
            'initial_balance'   => $data['initial_balance'],
            'remaining_balance' => $data['initial_balance'],
            'currency'          => $data['currency'] ?? 'USD',
            'expires_at'        => $data['expires_at'] ?? null,
            'customer_id'       => $data['customer_id'] ?? null,
            'status'            => 'active',
            'tenant_id'         => auth()->user()->tenant_id,
        ]);

        GiftCardTransaction::create([
            'gift_card_id' => $card->id,
            'type'         => 'issue',
            'amount'       => $card->initial_balance,
            'balance_after' => $card->initial_balance,
        ]);

        return response()->json(['data' => $this->cardResource($card)], 201);
    }

    public function show(GiftCard $card)
    {
        $card->load('customer', 'transactions');

        return response()->json([
            'data' => array_merge($this->cardResource($card), [
                'transactions' => $card->transactions,
            ]),
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $card = GiftCard::where('code', $request->code)->first();

        if (! $card) {
            return response()->json(['message' => 'Gift card not found.'], 404);
        }

        if ($card->status === 'void') {
            return response()->json(['message' => 'This gift card has been voided.'], 422);
        }

        if ($card->status === 'exhausted') {
            return response()->json(['message' => 'This gift card has no remaining balance.'], 422);
        }

        if ($card->expires_at && $card->expires_at->isPast()) {
            if ($card->status === 'active') {
                $card->update(['status' => 'expired']);
            }
            return response()->json(['message' => 'This gift card has expired.'], 422);
        }

        return response()->json(['data' => $this->cardResource($card)]);
    }

    public function redeem(Request $request, GiftCard $card)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($card->status !== 'active') {
            return response()->json(['message' => "Cannot redeem a {$card->status} gift card."], 422);
        }

        if ($card->expires_at && $card->expires_at->isPast()) {
            $card->update(['status' => 'expired']);
            return response()->json(['message' => 'This gift card has expired.'], 422);
        }

        $amount = (float) $request->amount;

        if ($amount > (float) $card->remaining_balance) {
            return response()->json([
                'message' => "Amount exceeds remaining balance of {$card->remaining_balance}.",
            ], 422);
        }

        $newBalance = round((float) $card->remaining_balance - $amount, 2);

        $card->update([
            'remaining_balance' => $newBalance,
            'status'            => $newBalance <= 0 ? 'exhausted' : 'active',
        ]);

        GiftCardTransaction::create([
            'gift_card_id'  => $card->id,
            'type'          => 'redeem',
            'amount'        => $amount,
            'balance_after' => $newBalance,
        ]);

        return response()->json(['data' => $this->cardResource($card->fresh())]);
    }

    public function void(GiftCard $card)
    {
        if ($card->status === 'void') {
            return response()->json(['message' => 'Gift card is already voided.'], 422);
        }

        $card->update(['status' => 'void']);

        GiftCardTransaction::create([
            'gift_card_id'  => $card->id,
            'type'          => 'void',
            'amount'        => 0,
            'balance_after' => $card->remaining_balance,
        ]);

        return response()->json(['data' => $this->cardResource($card->fresh())]);
    }
}
