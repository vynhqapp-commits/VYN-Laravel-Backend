<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Debt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'customer_id', 'invoice_id', 'original_amount', 'paid_amount', 'remaining_amount', 'status', 'due_date'];

    protected $casts = ['original_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'remaining_amount' => 'decimal:2', 'due_date' => 'date'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function payments() { return $this->hasMany(DebtPayment::class); }
    public function ledgerEntries() { return $this->hasMany(DebtLedgerEntry::class); }
    public function writeOffRequests() { return $this->hasMany(DebtWriteOffRequest::class); }
}
