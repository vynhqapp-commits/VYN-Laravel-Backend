<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'cash_drawer_session_id',
        'type',
        'amount',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function session() { return $this->belongsTo(CashDrawerSession::class, 'cash_drawer_session_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}

