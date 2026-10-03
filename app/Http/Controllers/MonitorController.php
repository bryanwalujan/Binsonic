<?php

namespace App\Http\Controllers;

use App\Models\BinsonicEvent;
use App\Models\BinsonicReading;
use App\Models\Device;
use App\Models\TerraReading;
use App\Services\GasResponse;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MonitorController extends Controller
{
    private function mode(Request $r): bool
    {
        $r->validate(['mode' => 'nullable|in:real,simulation']);

        abort_if(app()->environment('production') && $r->input('mode') === 'simulation', 403, 'Tampilan production hanya menggunakan data nyata.');

        return $r->input('mode') === 'simulation';
    }

    public function snapshot(Request $r): array
    {
        $sim = $this->mode($r);
        $b = BinsonicEvent::where('is_simulated', $sim);
        $r->validate(['terra_device' => 'nullable|integer']);
        $terraDevices = Device::where('type', 'terra')->where('is_simulated', $sim);
        $selected = $r->filled('terra_device') ? (clone $terraDevices)->find($r->integer('terra_device')) : null;
        abort_if($r->filled('terra_device') && ! $selected, 422, 'Perangkat TERRA tidak tersedia pada mode ini.');
        if (! $selected) {
            $latestDeviceId = TerraReading::where('is_simulated', $sim)->orderByDesc('received_at')->value('device_id');
            $selected = $latestDeviceId ? (clone $terraDevices)->find($latestDeviceId) : (clone $terraDevices)->orderBy('id')->first();
        }
        $t = TerraReading::where('is_simulated', $sim)->where('device_id', $selected?->id);
        $gas = app(GasResponse::class)->snapshot($selected);
        $counts = (clone $b)->selectRaw('destination, count(*) as total')->groupBy('destination')->get()->mapWithKeys(fn ($row) => [$row->destination ?? 'undecided' => $row->total]);
        $latest = (clone $t)->orderByRaw('COALESCE(captured_at, received_at) DESC')->first();
        $days = collect(range(6, 0))->map(fn ($i) => now('Asia/Makassar')->subDays($i)->format('Y-m-d'));
        $start = CarbonImmutable::parse($days->first(), 'Asia/Makassar')->utc();
        $activity = (clone $b)->whereRaw('COALESCE(captured_at, gateway_received_at, received_at) >= ?', [$start])->whereRaw('COALESCE(captured_at, gateway_received_at, received_at) <= ?', [now()->format('Y-m-d H:i:s.u')])->get(['captured_at', 'gateway_received_at', 'received_at'])->groupBy(fn ($row) => ($row->captured_at ?? $row->gateway_received_at ?? $row->received_at)->setTimezone('Asia/Makassar')->format('Y-m-d'));
        $readings = (clone $t)->orderByRaw('COALESCE(captured_at, received_at) DESC')->limit(60)->get()->reverse()->values();
        $sensorReadings = BinsonicReading::with('device')->where('is_simulated', $sim)->orderByDesc('received_at')->limit(10)->get();
        $devices = Device::where('is_simulated', $sim)->get()->map(function ($d) {
            $model = $d->type === 'binsonic' ? BinsonicEvent::class : TerraReading::class;
            $last = $model::where('device_id', $d->id)->orderByDesc('captured_at')->first();

            if ($d->type === 'binsonic') {
                $reading = BinsonicReading::where('device_id', $d->id)->orderByDesc('received_at')->first();
                if ($reading && (! $last || $reading->received_at->gt($last->received_at))) {
                    $last = $reading;
                }
            }
            $observedAt = $last ? ($last->captured_at ?? $last->gateway_received_at ?? $last->received_at) : null;

            return ['name' => $d->name, 'type' => $d->type, 'online' => $d->online(), 'connection' => $d->last_seen_at ? ($d->online() ? 'Online' : 'Offline') : 'Belum terhubung', 'seen' => Display::time($d->last_seen_at),
                'sensor' => $last ? ($observedAt->lt(now()->subSeconds(config('monitor.stale_seconds'))) ? 'Data kedaluwarsa' : 'Data terbaru') : 'Data belum tersedia'];
        });

        return ['gas' => $gas, 'terra_device_id' => $selected?->id, 'simulated' => $sim, 'counts' => $counts, 'total' => (clone $b)->count(), 'binsonic_readings' => $sensorReadings->map(fn ($v) => ['device' => $v->device->name, 'simulated' => $v->is_simulated, 'captured_at' => Display::time($v->captured_at), 'gateway_received_at' => Display::time($v->gateway_received_at), 'received_at' => Display::time($v->received_at), 'loadcell_raw' => $v->loadcell_raw, 'weight_g' => $v->weight_g, 'weight_calibrated' => $v->weight_calibrated, 'moisture_adc' => $v->moisture_adc, 'wet_contact' => $v->wet_contact, 'metal_detected' => $v->metal_detected, 'sensor_status' => $v->sensor_status]), 'devices' => $devices,
            'temperature' => $latest?->temperature_c, 'temperature_time' => Display::time($latest ? ($latest->captured_at ?? $latest->received_at) : null),
            'temperature_stale' => ! $latest || $latest->temperature_c === null || ($latest->captured_at ?? $latest->received_at)->lt(now()->subSeconds(config('monitor.stale_seconds'))),
            'activity' => ['labels' => $days->values(), 'values' => $days->map(fn ($day) => isset($activity[$day]) ? $activity[$day]->count() : 0)->values()],
            'terra' => ['labels' => $readings->map(fn ($v) => Display::time($v->captured_at ?? $v->received_at)), 'temperature' => $readings->pluck('temperature_c'), 'adc' => $readings->pluck('mq7_adc')],
            'updated' => Display::time(now())];
    }

    public function dashboard(Request $r)
    {
        return view('dashboard', ['snapshot' => $this->snapshot($r)]);
    }

    public function history(Request $r)
    {
        $sim = $this->mode($r);
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'label' => 'nullable|string|max:100', 'destination' => 'nullable|in:recycle,organic,thermal_candidate,residue_unknown,undecided', 'device' => 'nullable|integer']);
        $q = BinsonicEvent::with('device')->where('is_simulated', $sim);
        if ($r->filled('from')) {
            $q->whereRaw('COALESCE(captured_at, gateway_received_at, received_at) >= ?', [CarbonImmutable::parse($r->from, 'Asia/Makassar')->startOfDay()->utc()]);
        }
        if ($r->filled('to')) {
            $q->whereRaw('COALESCE(captured_at, gateway_received_at, received_at) <= ?', [CarbonImmutable::parse($r->to, 'Asia/Makassar')->endOfDay()->utc()]);
        }
        if ($r->filled('label')) {
            $q->where(function ($q) use ($r) {
                foreach (['final_label', 'audio_label', 'vision_label'] as $col) {
                    $q->orWhere($col, 'like', '%'.$r->label.'%');
                }
            });
        }
        if ($r->filled('destination')) {
            $r->destination === 'undecided' ? $q->whereNull('destination') : $q->where('destination', $r->destination);
        }
        if ($r->filled('device')) {
            $q->where('device_id', $r->device);
        }

        return view('history', ['events' => $q->orderByRaw('COALESCE(captured_at, gateway_received_at, received_at) DESC')->paginate(20)->withQueryString(), 'devices' => Device::where('type', 'binsonic')->where('is_simulated', $sim)->get()]);
    }

    public function detail(BinsonicEvent $event)
    {
        abort_if(app()->environment('production') && $event->is_simulated, 404);

        return view('detail', compact('event'));
    }

    public function photo(BinsonicEvent $event)
    {
        abort_if(app()->environment('production') && $event->is_simulated, 404);
        abort_unless($event->image_path && Storage::disk('local')->exists($event->image_path), 404);

        return response()->file(Storage::disk('local')->path($event->image_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function terra(Request $r)
    {
        $snapshot = $this->snapshot($r);

        return view('terra', ['snapshot' => $snapshot,
            'terraDevices' => Device::where('type', 'terra')->where('is_simulated', $snapshot['simulated'])->orderBy('name')->get(),
            'readings' => TerraReading::with('device')->where('is_simulated', $snapshot['simulated'])->where('device_id', $snapshot['terra_device_id'])->orderByRaw('COALESCE(captured_at, received_at) DESC')->paginate(20)->withQueryString()]);
    }
}
