<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'customer_id', 'staff_id', 'starts_at', 'ends_at', 'status', 'source', 'notes'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function staff() { return $this->belongsTo(Staff::class); }
    public function services() { return $this->hasMany(AppointmentService::class); }
    public function invoice() { return $this->hasOne(Invoice::class); }
}
