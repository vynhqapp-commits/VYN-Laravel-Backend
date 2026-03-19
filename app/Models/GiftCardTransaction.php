<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftCardTransaction extends Model
{
    protected $fillable = ['gift_card_id', 'invoice_id', 'type', 'amount', 'balance_after'];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];

    public function giftCard() { return $this->belongsTo(GiftCard::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
