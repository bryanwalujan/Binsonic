<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Keep the existing per-device event_id unique index; reading_id is the TERRA wire name.
        if ($this->attributes->get('device')->type === 'terra' && $this->has('reading_id') && ! $this->has('event_id')) {
            $this->merge(['event_id' => $this->input('reading_id')]);
        }
    }

    public function rules(): array
    {
        $base = [
            'event_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'captured_at' => ['present', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'sensor_status' => ['present', 'nullable', 'array:audio,vision,loadcell,moisture,metal,thermocouple,mq7'],
            'sensor_status.*' => ['nullable', Rule::in(['ok', 'error', 'unavailable', 'uncalibrated'])],
            'device_id' => ['prohibited'], 'is_simulated' => ['prohibited'], 'image_path' => ['prohibited'], 'image_url' => ['prohibited'], 'received_at' => ['prohibited'],
        ];
        if ($this->attributes->get('device')->type === 'binsonic') {
            $timestamp = ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
            $base['captured_at'] = array_merge(['present'], $timestamp);
            $base['gateway_received_at'] = $timestamp;

            return $base + [
                'audio_label' => ['nullable', 'string', 'max:100'], 'vision_label' => ['nullable', 'string', 'max:100'], 'final_label' => ['nullable', 'string', 'max:100'],
                'audio_confidence' => ['nullable', 'numeric', 'between:0,1'], 'vision_confidence' => ['nullable', 'numeric', 'between:0,1'],
                'destination' => ['nullable', Rule::in(['recycle', 'organic', 'thermal_candidate', 'residue_unknown'])],
                'weight_g' => ['nullable', 'numeric', 'min:0'], 'loadcell_raw' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
                'weight_calibrated' => ['required', 'boolean'], 'moisture_adc' => ['nullable', 'integer', 'between:0,65535'],
                'wet_contact' => ['nullable', 'boolean'], 'metal_detected' => ['nullable', 'boolean'],
            ];
        }

        return $base + ['reading_id' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'mq7_voltage_basis' => ['nullable', Rule::in(['adc_pin', 'sensor_output'])],
            'temperature_c' => ['nullable', 'numeric', 'between:0,1024'], 'thermocouple_ok' => ['nullable', 'boolean'],
            'mq7_adc' => ['nullable', 'integer', 'between:0,65535'], 'mq7_voltage' => ['nullable', 'numeric', 'between:0,5.5'],
            'adc_resolution_bits' => ['nullable', 'integer', 'between:1,16']];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($this->has('reading_id') && $this->input('reading_id') !== $this->input('event_id')) {
                $v->errors()->add('reading_id', 'reading_id dan event_id harus sama jika keduanya dikirim.');
            }
            if ($this->filled('mq7_voltage_basis') && $this->input('mq7_voltage') === null) {
                $v->errors()->add('mq7_voltage', 'Tegangan diperlukan jika definisi tegangan diberikan.');
            }
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $this->rules())) {
                    $v->errors()->add($key, 'Field tidak dikenali.');
                }
            }
            if ($this->input('weight_g') !== null && ! $this->boolean('weight_calibrated')) {
                $v->errors()->add('weight_g', 'Berat harus null jika belum dikalibrasi.');
            }
            if (is_numeric($this->mq7_adc) && is_numeric($this->adc_resolution_bits) && $this->adc_resolution_bits >= 1 && $this->adc_resolution_bits <= 16 && $this->mq7_adc >= 2 ** $this->adc_resolution_bits) {
                $v->errors()->add('mq7_adc', 'ADC melebihi resolusi yang diberikan.');
            }
        }];
    }
}
