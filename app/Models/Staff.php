<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'user_id', 'name', 'phone', 'specialization', 'pricing_tier_label', 'photo_url', 'color', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function schedules()
    {
        return $this->hasMany(StaffSchedule::class);
    }
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
    public function commissionRules()
    {
        return $this->hasMany(CommissionRule::class);
    }
    public function commissionEntries()
    {
        return $this->hasMany(CommissionEntry::class);
    }
    public function services()
    {
        return $this->belongsToMany(Service::class, 'staff_service')->withTimestamps();
    }
}
