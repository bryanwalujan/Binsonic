<?php

namespace App\Http\Controllers;

use App\Http\Requests\BinsonicReadingRequest;
use App\Http\Requests\TelemetryRequest;
use App\Models\BinsonicEvent;
use App\Models\BinsonicReading;
use App\Models\TerraReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TelemetryController extends Controller
{
    public function identity(Request $request)
    {
        $device = $request->attributes->get('device');

        return response()->json(['device_id' => $device->id, 'type' => $device->type, 'is_simulated' => $device->is_simulated, 'online_seconds' => config('monitor.online_seconds')]);
    }

    public function heartbeat(Request $request)
    {
        $request->validate(['device_id' => 'prohibited', 'is_simulated' => 'prohibited']);
        $request->attributes->get('device')->update(['last_seen_at' => now()]);

        return response()->json(['status' => 'ok', 'is_simulated' => $request->attributes->get('device')->is_simulated, 'server_time' => now()->toISOString()]);
    }

    public function store(TelemetryRequest $request)
    {
        $device = $request->attributes->get('device');
        $model = $device->type === 'binsonic' ? BinsonicEvent::class : TerraReading::class;

        return $this->persist($request, $model);
    }

    public function readings(BinsonicReadingRequest $request)
    {
        return $this->persist($request, BinsonicReading::class);
    }

    private function persist(TelemetryRequest $request, string $model)
    {
        $device = $request->attributes->get('device');
        $data = $request->validated();
        foreach (['device_id', 'is_simulated', 'image_path', 'image_url', 'received_at', 'reading_id'] as $key) {
            unset($data[$key]);
        }
        foreach (['captured_at', 'gateway_received_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->utc()->format('Y-m-d H:i:s.u');
            }
        }
        $numeric = ['audio_confidence', 'vision_confidence', 'weight_g', 'temperature_c', 'mq7_voltage'];
        $boolean = ['weight_calibrated', 'wet_contact', 'metal_detected', 'thermocouple_ok'];
        $integer = ['loadcell_raw', 'moisture_adc', 'mq7_adc', 'adc_resolution_bits'];
        foreach ($request->rules() as $key => $rules) {
            if (str_contains($key, '.') || in_array($key, ['device_id', 'is_simulated', 'image_path', 'image_url', 'received_at', 'reading_id'])) {
                continue;
            }
            $data[$key] = $data[$key] ?? null;
            if ($data[$key] !== null) {
                if (in_array($key, $numeric)) {
                    $data[$key] = (float) $data[$key];
                }
                if (in_array($key, $boolean)) {
                    $data[$key] = (bool) $data[$key];
                }
                if (in_array($key, $integer)) {
                    $data[$key] = (int) $data[$key];
                }
            }
        }
        if (is_array($data['sensor_status'])) {
            ksort($data['sensor_status']);
        }
        // Preserve hashes of legacy/direct-device events that predate the gateway field.
        if (($data['gateway_received_at'] ?? null) === null) {
            unset($data['gateway_received_at']);
        }
        // A missing voltage definition must preserve hashes of existing readings.
        if (($data['mq7_voltage_basis'] ?? null) === null) {
            unset($data['mq7_voltage_basis']);
        }
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($device, $model, $data, $hash) {
            // Lock the device to serialize concurrent retries; the unique index is the final guard.
            $device = $device->newQuery()->lockForUpdate()->findOrFail($device->id);
            $record = $model::where('device_id', $device->id)->where('event_id', $data['event_id'])->first();
            if ($record && ! hash_equals($record->payload_hash, $hash)) {
                abort(409, 'event_id sudah digunakan dengan payload berbeda.');
            }
            $created = ! $record;
            if (! $record) {
                $record = $model::create($data + ['device_id' => $device->id, 'received_at' => now(), 'is_simulated' => $device->is_simulated, 'payload_hash' => $hash]);
            }
            // Queue delivery is not proof that the USB board is connected right now.
            // Gateway traffic uses fresh, non-queued heartbeats to update connectivity.
            if (! isset($data['gateway_received_at'])) {
                $device->update(['last_seen_at' => now()]);
            }

            return response()->json(['data' => $record->refresh()], $created ? 201 : 200);
        });
    }

    public function image(Request $request, string $event_id)
    {
        $device = $request->attributes->get('device');
        $event = BinsonicEvent::where('device_id', $device->id)->where('event_id', $event_id)->firstOrFail();
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', function ($attribute, $file, $fail) {
            $info = @getimagesize($file->getRealPath());
            if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
                $fail('Isi berkas harus berupa gambar JPEG, PNG, atau WebP.');
            }
        }]]);

        return DB::transaction(function () use ($request, $event, $device) {
            $event = BinsonicEvent::lockForUpdate()->findOrFail($event->id);
            abort_if($event->image_path, 409, 'Foto sudah tersedia.');
            $path = $request->file('image')->store('events', 'local');
            abort_unless($path, 500, 'Penyimpanan foto gagal.');
            try {
                $event->update(['image_path' => $path]);
                if (! $event->gateway_received_at) {
                    $device->update(['last_seen_at' => now()]);
                }
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }

            return response()->json(['status' => 'Foto tersimpan.'], 201);
        });
    }
}
