<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'entity_type',
        'entity_id',
        'requested_action',
        'requested_by',
        'decided_by',
        'payload',
        'status',
        'expires_at',
        'decided_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }
}

