<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id',
        'tenant_id',
        'action',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
}

