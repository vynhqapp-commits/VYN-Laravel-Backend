<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentService extends Model
{
    protected $fillable = ['appointment_id', 'service_id', 'price', 'duration_minutes'];

    protected $casts = ['price' => 'decimal:2'];

    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function service() { return $this->belongsTo(Service::class); }
}
