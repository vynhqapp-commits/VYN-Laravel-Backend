<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TimeOffRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'staff_id',
        'branch_id',
        'start_date',
        'end_date',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'decision_note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
