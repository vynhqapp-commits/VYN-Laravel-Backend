<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TipAllocation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'staff_id',
        'invoice_id',
        'amount',
        'earned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'earned_at' => 'datetime',
    ];

    public function staff() { return $this->belongsTo(Staff::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}

