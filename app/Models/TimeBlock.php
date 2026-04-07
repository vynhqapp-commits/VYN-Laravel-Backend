<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TimeBlock extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'staff_id',
        'starts_at',
        'ends_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function staff() { return $this->belongsTo(Staff::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}

