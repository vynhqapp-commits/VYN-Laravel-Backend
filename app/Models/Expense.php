<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'branch_id', 'category', 'description', 'amount', 'expense_date', 'payment_method'];

    protected $casts = ['amount' => 'decimal:2', 'expense_date' => 'date'];

    public function branch() { return $this->belongsTo(Branch::class); }
}
