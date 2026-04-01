<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'name', 'phone', 'email', 'birthday', 'gender', 'tags'];

    protected $casts = ['birthday' => 'date'];

    public function user() { return $this->belongsTo(User::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function debts() { return $this->hasMany(Debt::class); }
    public function notes() { return $this->hasMany(CustomerNote::class); }
    public function favorites() { return $this->hasMany(CustomerFavorite::class); }
    public function giftCards() { return $this->hasMany(GiftCard::class); }
    public function memberships() { return $this->hasMany(CustomerMembership::class); }
    public function servicePackages() { return $this->hasMany(CustomerServicePackage::class); }
}
