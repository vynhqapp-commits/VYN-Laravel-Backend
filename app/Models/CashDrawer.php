<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashDrawer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function sessions() { return $this->hasMany(CashDrawerSession::class); }
    public function activeSession() { return $this->hasOne(CashDrawerSession::class)->where('status', 'open'); }
}
