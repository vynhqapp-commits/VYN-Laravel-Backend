<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FranchiseOwnerInvitation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invited_by',
        'email',
        'name',
        'branch_ids',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'branch_ids' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function inviter() { return $this->belongsTo(User::class, 'invited_by'); }
}

