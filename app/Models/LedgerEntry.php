<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'type', 'category', 'amount', 'tax_amount', 'reference_type', 'reference_id', 'description', 'entry_date', 'is_locked'];

    protected $casts = ['amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'entry_date' => 'date', 'is_locked' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function reference() { return $this->morphTo(); }
}
