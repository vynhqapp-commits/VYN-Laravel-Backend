<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name'];

    public function services() { return $this->hasMany(Service::class); }
}
