<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    public const TYPES = [
        'in',
        'out',
        'sold',
        'service_deduction',
        'service_usage',
        'adjustment',
        'return',
        'damage',
        'theft',
        'expired',
        'transfer',
    ];

    protected $fillable = ['tenant_id', 'branch_id', 'product_id', 'type', 'quantity', 'reason', 'reference_type', 'reference_id'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function reference() { return $this->morphTo(); }
}
