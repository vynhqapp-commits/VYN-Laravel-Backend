<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'invoice_id', 'method', 'amount', 'reference', 'status', 'cash_drawer_session_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function cashDrawerSession() { return $this->belongsTo(CashDrawerSession::class); }
}
