<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DebtWriteOffRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'debt_id',
        'requested_by',
        'approved_by',
        'amount',
        'reason',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function debt() { return $this->belongsTo(Debt::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}

