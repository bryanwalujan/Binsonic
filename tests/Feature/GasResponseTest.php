<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\TerraReading;
use App\Models\User;
use App\Services\GasResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class GasResponseTest extends TestCase
{
    use DatabaseTransactions;

    private function board(bool $simulated = false): Device
    {
        return Device::create(['name' => $simulated ? 'Hidden simulation' : 'TERRA actual DB', 'type' => 'terra', 'is_simulated' => $simulated]);
    }

    private function reading(Device $board, int $secondsAgo, ?int $adc, array $extra = []): TerraReading
    {
        return TerraReading::create(array_replace(['device_id' => $board->id, 'event_id' => (string) Str::uuid(),
            'captured_at' => now()->subSeconds($secondsAgo), 'received_at' => now()->subSeconds($secondsAgo),
            'mq7_adc' => $adc, 'adc_resolution_bits' => 12, 'sensor_status' => null,
            'is_simulated' => $board->is_simulated, 'payload_hash' => str_repeat('0', 64)], $extra));
    }

    private function gas(Device $board): array
    {
        return app(GasResponse::class)->snapshot($board);
    }

    public function test_empty_dashboard_null_measurements_and_never_connected_device(): void
    {
        $board = $this->board();
        $this->actingAs(User::factory()->create());
        $this->getJson('/dashboard/snapshot')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('temperature', null)
            ->assertJsonPath('gas.state', 'waiting')->assertJsonPath('gas.points', [])->assertJsonPath('gas.detail.adc', null)
            ->assertJsonPath('devices.0.connection', 'Belum terhubung');
        $this->get('/terra')->assertOk()->assertSee('Respons Sensor Gas')->assertSee('Menunggu data sensor')->assertSee('Belum ada data');
        $this->assertNull($board->fresh()->last_seen_at);
    }

    public function test_one_sample_and_one_period_cannot_determine_direction(): void
    {
        $board = $this->board();
        $this->reading($board, 10, 100);
        $this->assertSame('insufficient', $this->gas($board)['state']);
        foreach ([20, 30, 40, 50, 60] as $seconds) {
            $this->reading($board, $seconds, 200);
        }
        $gas = $this->gas($board);
        $this->assertNull($gas['direction']);
        $this->assertSame('Data belum cukup untuk melihat perubahan', $gas['message']);
    }

    public function test_increasing_stable_and_decreasing_use_two_periods_and_leave_raw_data_unchanged(): void
    {
        $this->travelTo(now()->startOfSecond());
        foreach (['increasing' => [100, 200], 'stable' => [100, 120], 'decreasing' => [200, 100]] as $direction => [$before, $after]) {
            $board = $this->board();
            foreach (range(270, 0, -30) as $age) {
                $this->reading($board, $age, $age > 150 ? $before : $after);
            }
            $original = TerraReading::where('device_id', $board->id)->pluck('mq7_adc', 'id')->all();
            $gas = $this->gas($board);
            $this->assertSame('available', $gas['state']);
            $this->assertSame($direction, $gas['direction']);
            $this->assertSame(10, $gas['sample_count']);
            $this->assertSame($original, TerraReading::where('device_id', $board->id)->pluck('mq7_adc', 'id')->all());
            if ($direction === 'increasing') {
                $this->assertSame('Pembacaan sensor meningkat dibanding periode sebelumnya.', $gas['message']);
            }
        }
    }

    public function test_display_thresholds_are_configurable_and_smoothing_is_median_of_three(): void
    {
        $this->travelTo(now()->startOfSecond());
        $board = $this->board();
        foreach (range(270, 0, -30) as $age) {
            $this->reading($board, $age, $age > 150 ? 100 : 200);
        }
        config(['monitor.gas.absolute_delta' => 150]);
        $this->assertSame('stable', $this->gas($board)['direction']);
        config(['monitor.gas.absolute_delta' => 30]);
        $this->assertSame('increasing', $this->gas($board)['direction']);
        $this->reading($board, 0, 3000, ['captured_at' => now()->subSecond()]);
        $gas = $this->gas($board);
        $this->assertSame(200.0, $gas['points'][count($gas['points']) - 2]['y']);
        $this->assertSame(3000, TerraReading::where('device_id', $board->id)->orderByDesc('id')->first()->mq7_adc);
    }

    public function test_late_disconnected_and_long_gap_data_are_not_claimed_current_or_connected(): void
    {
        $board = $this->board();
        foreach ([270, 240, 210, 30, 15, 0] as $age) {
            $this->reading($board, $age, 100 + $age);
        }
        $gas = $this->gas($board);
        $this->assertSame('insufficient', $gas['state']);
        $this->assertSame(3, $gas['sample_count']);
        $this->assertContains(null, array_column($gas['points'], 'y'));
        $this->travel(121)->seconds();
        $this->assertSame('stale', $this->gas($board)['state']);
        $this->assertSame('Data tidak terbaru', $this->gas($board)['availability']);
        $late = $this->board();
        $this->reading($late, 600, 123, ['received_at' => now()]);
        $this->assertSame('stale', $this->gas($late)['state']);
    }

    public function test_null_error_resolution_changes_and_distinct_clock_sources_break_series(): void
    {
        $this->travelTo(now()->startOfSecond());
        foreach ([['mq7_adc' => null], ['sensor_status' => ['mq7' => 'error']], ['adc_resolution_bits' => 10], ['captured_at' => null]] as $change) {
            $board = $this->board();
            foreach (range(270, 0, -30) as $age) {
                $this->reading($board, $age, 200, $age === 60 ? $change : []);
            }
            $gas = $this->gas($board);
            $this->assertNull($gas['direction']);
            $this->assertContains(null, array_column($gas['points'], 'y'));
        }
        $empty = $this->board();
        $this->reading($empty, 0, null);
        $this->assertNull($this->gas($empty)['detail']['adc']);
        $this->assertSame([], array_filter($this->gas($empty)['points'], fn ($point) => $point['y'] !== null));
        $zero = $this->board();
        $this->reading($zero, 0, 0);
        $this->assertSame(0, $this->gas($zero)['detail']['adc']);
        $this->assertSame('insufficient', $this->gas($zero)['state']);
    }

    public function test_nullable_device_clock_voltage_definitions_and_incomplete_api_payload(): void
    {
        $board = $this->board();
        $token = $board->rotateToken();
        $payload = ['reading_id' => 'nullable-clock', 'captured_at' => null, 'sensor_status' => null,
            'mq7_adc' => null, 'mq7_voltage' => 3.2, 'mq7_voltage_basis' => 'sensor_output'];
        $this->withToken($token)->postJson('/api/v1/terra/readings', $payload)->assertCreated()->assertJsonPath('data.captured_at', null)->assertJsonPath('data.temperature_c', null);
        $this->withToken($token)->postJson('/api/v1/terra/readings', $payload)->assertOk();
        $gas = $this->gas($board);
        $this->assertSame(3.2, $gas['detail']['sensor_output_voltage']);
        $this->assertNull($gas['detail']['adc_pin_voltage']);
        $this->assertStringContainsString('penerimaan server', $gas['time_source']);
        $this->assertNull($gas['direction']);
        $this->withToken($token)->postJson('/api/v1/terra/readings', ['reading_id' => 'incomplete'])->assertUnprocessable()->assertJsonValidationErrors(['captured_at', 'sensor_status']);
        $this->actingAs(User::factory()->create())->get('/terra')->assertOk()->assertSee('Belum dikalibrasi untuk konsentrasi CO');
    }

    public function test_production_hides_simulation_without_deleting_records_and_never_mixes_devices(): void
    {
        $real = $this->board();
        $sim = $this->board(true);
        $other = $this->board();
        $this->reading($real, 0, 123);
        $this->reading($sim, 0, 999);
        $this->reading($other, 0, 456);
        $this->actingAs(User::factory()->create());
        $token = $sim->rotateToken();
        $this->app['env'] = 'production';
        $this->withToken($token)->postJson('/api/v1/heartbeat', [])->assertForbidden();
        $this->assertNull($sim->fresh()->last_seen_at);
        $this->getJson('/dashboard/snapshot?terra_device='.$real->id)->assertOk()->assertJsonPath('gas.detail.adc', 123)->assertJsonPath('terra.adc', [123]);
        $this->getJson('/dashboard/snapshot?terra_device='.$sim->id)->assertUnprocessable();
        $this->get('/terra?mode=simulation')->assertForbidden();
        $this->get('/devices')->assertOk()->assertDontSee('Hidden simulation');
        $this->get('/terra')->assertOk()->assertDontSee('SIMULASI');
        $this->assertDatabaseCount('terra_readings', 3);
    }

    public function test_future_clock_and_duplicate_timestamps_do_not_manufacture_a_trend(): void
    {
        $this->travelTo(now()->startOfSecond());
        $board = $this->board();
        foreach (range(1, 8) as $i) {
            $this->reading($board, 180, 100);
            $this->reading($board, 150, 100);
            $this->reading($board, 120, 200);
        }
        $this->assertNull($this->gas($board)['direction']);
        $future = $this->board();
        $this->reading($future, -60, 100, ['received_at' => now()]);
        $gas = $this->gas($future);
        $this->assertSame('unavailable', $gas['state']);
        $this->assertSame('Waktu pengukuran belum valid', $gas['availability']);
        $this->assertSame([], $gas['points']);
    }
}
