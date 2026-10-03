<?php

namespace App\Services;

use App\Models\Device;
use App\Models\TerraReading;
use App\Support\Display;

class GasResponse
{
    public function snapshot(?Device $device): array
    {
        $now = now();
        $window = max(60, (int) config('monitor.gas.window_seconds'));
        $gap = max(1, (int) config('monitor.gas.gap_seconds'));
        $minimum = max(2, (int) config('monitor.gas.min_samples_per_half'));
        $latest = $device ? TerraReading::where('device_id', $device->id)->orderByDesc('received_at')->orderByDesc('id')->first() : null;
        $time = $latest ? ($latest->captured_at ?? $latest->received_at) : null;
        $base = ['device_id' => $device?->id, 'device_name' => $device?->name, 'state' => 'waiting',
            'availability' => 'Menunggu data sensor', 'direction' => null, 'direction_label' => null,
            'message' => 'Menunggu data sensor', 'last_time' => Display::time($time),
            'time_source' => $latest && ! $latest->captured_at ? 'Waktu penerimaan server (jam perangkat belum tersedia)' : 'Waktu pengukuran perangkat',
            'points' => [], 'sample_count' => 0, 'window_seconds' => $window,
            'detail' => ['adc' => $latest?->mq7_adc, 'resolution_bits' => $latest?->adc_resolution_bits,
                'adc_pin_voltage' => $latest?->mq7_voltage_basis === 'adc_pin' ? $latest->mq7_voltage : null,
                'sensor_output_voltage' => $latest?->mq7_voltage_basis === 'sensor_output' ? $latest->mq7_voltage : null,
                'undefined_voltage' => $latest && ! $latest->mq7_voltage_basis ? $latest->mq7_voltage : null,
                'captured_at' => Display::time($latest?->captured_at), 'received_at' => Display::time($latest?->received_at),
                'reported_status' => $latest?->sensor_status['mq7'] ?? null]];
        if (! $latest) {
            return $base;
        }
        $rows = TerraReading::where('device_id', $device->id)
            ->whereRaw('COALESCE(captured_at, received_at) >= ?', [$now->copy()->subSeconds($window)->format('Y-m-d H:i:s.u')])
            ->whereRaw('COALESCE(captured_at, received_at) <= ?', [$now->format('Y-m-d H:i:s.u')])
            ->orderByRaw('COALESCE(captured_at, received_at) DESC')->orderByDesc('id')->limit(1200)->get()->reverse();
        $segment = [];
        $points = [];
        $recent = [];
        $previous = null;
        $resolution = null;
        $source = null;
        foreach ($rows as $row) {
            $at = $row->captured_at ?? $row->received_at;
            $clock = $row->captured_at ? 'device' : 'server';
            $valid = $row->mq7_adc !== null && ! in_array($row->sensor_status['mq7'] ?? null, ['error', 'unavailable']);
            $break = $previous && ($at->getTimestampMs() - $previous->getTimestampMs() > $gap * 1000 || $resolution !== $row->adc_resolution_bits || $source !== $clock);
            if ($break || ! $valid) {
                $points[] = ['x' => $break ? ($at->getTimestampMs() + $previous->getTimestampMs()) / 2 : $at->getTimestampMs(), 'y' => null];
                $segment = [];
                $recent = [];
            }
            if ($valid) {
                // Count distinct observation times; repeated timestamps do not manufacture evidence.
                $segment[$at->format('U.u')] = ['time' => $at, 'value' => $row->mq7_adc];
                $recent[] = $row->mq7_adc;
                $recent = array_slice($recent, -3);
                $points[] = ['x' => $at->getTimestampMs(), 'y' => $this->median($recent)];
            }
            $previous = $at;
            $resolution = $row->adc_resolution_bits;
            $source = $clock;
        }
        $base['points'] = $points;
        $base['sample_count'] = count($segment);
        if ($time->gt($now)) {
            return array_replace($base, ['state' => 'unavailable', 'availability' => 'Waktu pengukuran belum valid', 'message' => 'Periksa sinkronisasi waktu perangkat.']);
        }
        if ($time->lt($now->copy()->subSeconds(config('monitor.stale_seconds')))) {
            return array_replace($base, ['state' => 'stale', 'availability' => 'Data tidak terbaru', 'message' => 'Data tidak terbaru. Periksa waktu pembacaan terakhir.']);
        }
        if ($latest->mq7_adc === null || in_array($latest->sensor_status['mq7'] ?? null, ['error', 'unavailable'])) {
            return array_replace($base, ['state' => 'unavailable', 'availability' => 'Menunggu data sensor', 'message' => 'Pembacaan terakhir belum tersedia untuk melihat perubahan.']);
        }
        $base['state'] = 'insufficient';
        $base['availability'] = 'Data tersedia';
        $base['message'] = 'Data belum cukup untuk melihat perubahan';
        $middle = $now->copy()->subSeconds($window / 2);
        $older = [];
        $newer = [];
        foreach ($segment as $sample) {
            if ($sample['time']->lt($middle)) {
                $older[] = $sample['value'];
            } else {
                $newer[] = $sample['value'];
            }
        }
        if (count($older) < $minimum || count($newer) < $minimum) {
            return $base;
        }
        $before = $this->median($older);
        $after = $this->median($newer);
        $threshold = max(0, (float) config('monitor.gas.absolute_delta'), abs($before) * max(0, (float) config('monitor.gas.relative_delta')));
        $delta = $after - $before;
        $direction = abs($delta) <= $threshold ? 'stable' : ($delta > 0 ? 'increasing' : 'decreasing');
        $labels = ['increasing' => 'Meningkat', 'stable' => 'Relatif stabil', 'decreasing' => 'Menurun'];
        $messages = ['increasing' => 'Pembacaan sensor meningkat dibanding periode sebelumnya.',
            'stable' => 'Perubahan pembacaan kecil dibanding periode sebelumnya.',
            'decreasing' => 'Pembacaan sensor menurun dibanding periode sebelumnya.'];

        return array_replace($base, ['state' => 'available', 'direction' => $direction, 'direction_label' => $labels[$direction], 'message' => $messages[$direction]]);
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ($values[(int) floor(($count - 1) / 2)] + $values[(int) floor($count / 2)]) / 2;
    }
}
