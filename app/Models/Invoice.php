<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'customer_id', 'appointment_id', 'invoice_number', 'subtotal', 'discount', 'tax', 'total', 'paid_amount', 'status', 'notes'];

    protected $casts = ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function debt() { return $this->hasOne(Debt::class); }
    public function commissionEntries() { return $this->hasMany(CommissionEntry::class); }
}
