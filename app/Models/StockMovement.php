<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'product_id', 'type', 'quantity', 'reason', 'reference_type', 'reference_id'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function reference() { return $this->morphTo(); }
}
