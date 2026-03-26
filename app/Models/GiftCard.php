<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GiftCard extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'initial_balance', 'remaining_balance', 'currency', 'customer_id', 'expires_at', 'status'];

    protected $casts = ['initial_balance' => 'decimal:2', 'remaining_balance' => 'decimal:2', 'expires_at' => 'date'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function transactions() { return $this->hasMany(GiftCardTransaction::class); }
}
