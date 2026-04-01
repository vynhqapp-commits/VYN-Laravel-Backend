<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFavorite extends Model
{
    protected $fillable = ['customer_id', 'salon_id'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salon()
    {
        return $this->belongsTo(Tenant::class, 'salon_id');
    }
}
