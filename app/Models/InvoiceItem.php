<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'itemable_type', 'itemable_id', 'name', 'quantity', 'unit_price', 'discount', 'total'];

    protected $casts = ['unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function itemable() { return $this->morphTo(); }
}
