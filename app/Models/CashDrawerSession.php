<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDrawerSession extends Model
{
    protected $fillable = ['cash_drawer_id', 'opened_by', 'closed_by', 'opening_balance', 'closing_balance', 'expected_balance', 'discrepancy', 'opened_at', 'closed_at', 'status'];

    protected $casts = ['opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2', 'expected_balance' => 'decimal:2', 'discrepancy' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function cashDrawer() { return $this->belongsTo(CashDrawer::class); }
    public function openedBy() { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function movements() { return $this->hasMany(CashMovement::class, 'cash_drawer_session_id'); }
}
