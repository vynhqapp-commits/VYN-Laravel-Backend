<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'salon_id',
        'customer_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function salon()
    {
        return $this->belongsTo(Tenant::class, 'salon_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

