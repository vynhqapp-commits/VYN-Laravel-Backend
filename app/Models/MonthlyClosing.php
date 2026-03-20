<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MonthlyClosing extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'year',
        'month',
        'closed_by',
        'status',
        'notes',
        'closed_at',
    ];

    protected $casts = [
        'year'      => 'integer',
        'month'     => 'integer',
        'closed_at' => 'datetime',
    ];

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
