<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'phone', 'address', 'timezone', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function staff() { return $this->hasMany(Staff::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function cashDrawers() { return $this->hasMany(CashDrawer::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
}
