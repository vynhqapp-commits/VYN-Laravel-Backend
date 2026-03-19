<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CommissionEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'staff_id', 'invoice_id', 'commission_rule_id', 'base_amount', 'commission_amount', 'tip_amount', 'status'];

    protected $casts = ['base_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'tip_amount' => 'decimal:2'];

    public function staff() { return $this->belongsTo(Staff::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function rule() { return $this->belongsTo(CommissionRule::class, 'commission_rule_id'); }
}
