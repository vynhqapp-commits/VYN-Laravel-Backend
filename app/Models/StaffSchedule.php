<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StaffSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'staff_id', 'day_of_week', 'start_time', 'end_time', 'is_day_off'];

    protected $casts = ['is_day_off' => 'boolean'];

    public function staff() { return $this->belongsTo(Staff::class); }
}
