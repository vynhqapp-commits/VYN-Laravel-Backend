<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'service_category_id', 'name', 'description', 'duration_minutes', 'price', 'deposit_amount', 'cost', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'price' => 'decimal:2', 'deposit_amount' => 'decimal:2', 'cost' => 'decimal:2'];

    public function category() { return $this->belongsTo(ServiceCategory::class, 'service_category_id'); }
    public function pricingTiers() { return $this->hasMany(ServicePricingTier::class); }
    public function addOns() { return $this->hasMany(ServiceAddOn::class); }
    public function commissionRules() { return $this->hasMany(CommissionRule::class); }
    public function productUsages() { return $this->hasMany(ServiceProductUsage::class); }
}
