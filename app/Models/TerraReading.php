<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerraReading extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $appends = ['reading_id'];

    public function getReadingIdAttribute(): string
    {
        return $this->event_id;
    }

    protected $hidden = ['payload_hash', 'image_path'];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'sensor_status' => 'array', 'is_simulated' => 'boolean', 'temperature_c' => 'float', 'mq7_voltage' => 'float', 'thermocouple_ok' => 'boolean'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
