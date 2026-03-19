<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['identifier', 'type', 'code', 'purpose', 'is_used', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime', 'is_used' => 'boolean'];

    public function isValid(): bool
    {
        return !$this->is_used && $this->expires_at->isFuture();
    }
}
