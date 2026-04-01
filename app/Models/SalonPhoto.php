<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalonPhoto extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'salon_id',
        'url',
        'alt_text',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function salon()
    {
        return $this->belongsTo(Tenant::class, 'salon_id');
    }
}

