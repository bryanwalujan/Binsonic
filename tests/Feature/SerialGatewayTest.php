<?php

namespace Tests\Feature;

use App\Models\BinsonicEvent;
use App\Models\BinsonicReading;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SerialGatewayTest extends TestCase
{
    use DatabaseTransactions;

    private function device(string $type = 'binsonic', bool $simulation = false): array
    {
        $device = Device::create(['name' => 'Portenta USB test', 'type' => $type, 'is_simulated' => $simulation]);

        return [$device, $device->rotateToken()];
    }

    private function reading(array $extra = []): array
    {
        return array_replace([
            'event_id' => 'boot-abc-reading-1', 'captured_at' => null,
            'gateway_received_at' => '2026-10-02T05:00:00.123456Z',
            'weight_calibrated' => false, 'weight_g' => null, 'loadcell_raw' => 4724,
            'moisture_adc' => 556, 'wet_contact' => false, 'metal_detected' => null,
            'sensor_status' => ['loadcell' => 'uncalibrated', 'moisture' => 'ok', 'metal' => 'unavailable'],
        ], $extra);
    }

    public function test_identity_is_authenticated_and_does_not_send_heartbeat(): void
    {
        $this->getJson('/api/v1/device')->assertUnauthorized();
        [$device, $token] = $this->device();
        $this->withToken($token)->getJson('/api/v1/device')->assertOk()
            ->assertJsonPath('device_id', $device->id)->assertJsonPath('type', 'binsonic')->assertJsonPath('is_simulated', false);
        $this->assertNull($device->fresh()->last_seen_at);
    }

    public function test_readings_require_binsonic_token_and_validate_sensor_contract(): void
    {
        $this->postJson('/api/v1/binsonic/readings', $this->reading())->assertUnauthorized();
        [, $terra] = $this->device('terra');
        $this->withToken($terra)->postJson('/api/v1/binsonic/readings', $this->reading())->assertForbidden();
        [, $token] = $this->device();
        foreach ([['destination' => null], ['audio_label' => null], ['weight_g' => 1], ['sensor_status' => ['fake' => 'ok']], ['captured_at' => '2026-10-02 00:00:00'], ['is_simulated' => true], ['received_at' => now()->toISOString()], ['metal_detected' => 7], ['gateway_received_at' => 'bad']] as $extra) {
            $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading($extra))->assertUnprocessable();
        }
        $this->assertDatabaseCount('binsonic_readings', 0);
    }

    public function test_reading_null_and_all_three_clocks_are_preserved(): void
    {
        [$device, $token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading())
            ->assertCreated()->assertJsonPath('data.captured_at', null)->assertJsonPath('data.weight_g', null)
            ->assertJsonPath('data.wet_contact', false)->assertJsonPath('data.metal_detected', null);
        $row = BinsonicReading::firstOrFail();
        $this->assertSame('2026-10-02 05:00:00.123456', $row->gateway_received_at->format('Y-m-d H:i:s.u'));
        $this->assertTrue($row->received_at->diffInSeconds(now(), true) < 3);
        $this->assertNull($device->fresh()->last_seen_at);
    }

    public function test_gateway_reading_retries_are_idempotent_and_do_not_revive_offline_device(): void
    {
        [$device, $token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/heartbeat', [])->assertOk();
        $seen = $device->fresh()->last_seen_at;
        $first = $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading())->assertCreated()->json();
        $this->travel(61)->seconds();
        $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading())->assertOk()->assertExactJson($first);
        $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading(['loadcell_raw' => 999]))->assertConflict();
        $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading(['event_id' => 'next-backlog']))->assertCreated();
        $this->assertTrue($seen->equalTo($device->fresh()->last_seen_at));
        $this->assertFalse($device->fresh()->online());
        $this->assertDatabaseCount('binsonic_readings', 2);
        $this->assertDatabaseCount('binsonic_events', 0);
    }

    public function test_gateway_events_without_board_clock_stay_events_and_offline(): void
    {
        [$device, $token] = $this->device();
        $payload = $this->reading() + ['audio_label' => null, 'vision_label' => null, 'destination' => null];
        $first = $this->withToken($token)->postJson('/api/v1/binsonic/events', $payload)->assertCreated()->assertJsonPath('data.captured_at', null)->json();
        $this->withToken($token)->postJson('/api/v1/binsonic/events', $payload)->assertOk()->assertExactJson($first);
        $this->assertNull($device->fresh()->last_seen_at);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $this->get('/binsonic')->assertOk()->assertSee('Diterima gateway');
        $this->get('/binsonic/'.BinsonicEvent::first()->id)->assertOk()->assertSee('Diterima gateway (laptop)');
    }

    public function test_sensor_telemetry_is_separate_from_garbage_counts_and_simulation(): void
    {
        [, $real] = $this->device();
        [, $sim] = $this->device('binsonic', true);
        foreach ([$real, $sim] as $token) {
            $this->withToken($token)->postJson('/api/v1/binsonic/readings', $this->reading())->assertCreated();
        }
        $this->actingAs(User::factory()->create());
        foreach (['real' => false, 'simulation' => true] as $mode => $simulated) {
            $this->getJson('/dashboard/snapshot?mode='.$mode)->assertOk()->assertJsonPath('total', 0)
                ->assertJsonCount(1, 'binsonic_readings')->assertJsonPath('binsonic_readings.0.simulated', $simulated)
                ->assertJsonPath('binsonic_readings.0.metal_detected', null)->assertJsonPath('binsonic_readings.0.weight_calibrated', false);
            $this->get('/dashboard?mode='.$mode)->assertOk()->assertSee('Sensor BINSONIC');
        }
        $this->assertDatabaseCount('binsonic_events', 0);
    }

    public function test_direct_reading_with_device_clock_updates_last_seen_and_retains_utc(): void
    {
        [$device, $token] = $this->device();
        $payload = $this->reading(['captured_at' => '2026-10-02T13:00:00.123456+08:00']);
        unset($payload['gateway_received_at']);
        $this->withToken($token)->postJson('/api/v1/binsonic/readings', $payload)->assertCreated()
            ->assertJsonPath('data.captured_at', '2026-10-02T05:00:00.123456Z');
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_direct_device_without_calendar_clock_preserves_null_and_uses_server_reception(): void
    {
        [$device, $token] = $this->device();
        $payload = $this->reading(['gateway_received_at' => null]);
        $this->withToken($token)->postJson('/api/v1/binsonic/events', $payload)->assertCreated()->assertJsonPath('data.captured_at', null)->assertJsonPath('data.gateway_received_at', null);
        $this->withToken($token)->postJson('/api/v1/binsonic/events', $payload)->assertOk();
        $this->assertNotNull($device->fresh()->last_seen_at);
        $this->actingAs(User::factory()->create())->getJson('/dashboard/snapshot')->assertOk()->assertJsonPath('total', 1);
        $this->get('/binsonic')->assertOk()->assertSee('Diterima server');
    }
}
