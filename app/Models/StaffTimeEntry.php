<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StaffTimeEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'staff_id', 'branch_id', 'clock_in_at', 'clock_out_at'];

    protected $casts = ['clock_in_at' => 'datetime', 'clock_out_at' => 'datetime'];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
