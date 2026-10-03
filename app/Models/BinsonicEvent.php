<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BinsonicEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash', 'image_path'];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'gateway_received_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'sensor_status' => 'array', 'is_simulated' => 'boolean', 'audio_confidence' => 'float', 'vision_confidence' => 'float', 'weight_g' => 'float', 'weight_calibrated' => 'boolean', 'wet_contact' => 'boolean', 'metal_detected' => 'boolean'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
