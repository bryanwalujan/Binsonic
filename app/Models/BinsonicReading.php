<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BinsonicReading extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'gateway_received_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'sensor_status' => 'array', 'is_simulated' => 'boolean', 'weight_calibrated' => 'boolean', 'weight_g' => 'float', 'loadcell_raw' => 'integer', 'moisture_adc' => 'integer', 'wet_contact' => 'boolean', 'metal_detected' => 'boolean'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
