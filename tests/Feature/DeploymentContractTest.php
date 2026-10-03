<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\TerraReading;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeploymentContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_terra_reading_id_alias_definition_and_dashboard_isolation(): void
    {
        $device = Device::create(['name' => 'TERRA simulation unique', 'type' => 'terra', 'is_simulated' => true]);
        $token = $device->rotateToken();
        $payload = ['reading_id' => 'terra-unique', 'captured_at' => now()->toISOString(), 'temperature_c' => null,
            'thermocouple_ok' => false, 'mq7_adc' => 0, 'mq7_voltage' => 0, 'mq7_voltage_basis' => 'adc_pin',
            'adc_resolution_bits' => 12, 'sensor_status' => ['thermocouple' => 'error', 'mq7' => 'uncalibrated']];
        $url = '/api/v1/terra/readings';
        $first = $this->withToken($token)->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.reading_id', 'terra-unique')->assertJsonPath('data.device_id', $device->id)
            ->assertJsonPath('data.temperature_c', null)->assertJsonPath('data.mq7_adc', 0)
            ->assertJsonPath('data.mq7_voltage_basis', 'adc_pin')->json('data');
        $this->assertNotNull($first['received_at']);
        $this->withToken($token)->postJson($url, $payload)->assertOk()->assertExactJson(['data' => $first]);
        $legacy = $payload;
        unset($legacy['reading_id']);
        $legacy['event_id'] = 'terra-unique';
        $this->withToken($token)->postJson($url, $legacy)->assertOk();
        $this->withToken($token)->postJson($url, $payload + ['event_id' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('reading_id');
        $this->withToken($token)->postJson($url, array_replace($payload, ['mq7_voltage_basis' => 'ppm']))->assertUnprocessable();
        $this->withToken($token)->postJson($url, array_replace($payload, ['mq7_voltage' => null]))->assertUnprocessable();
        $this->withToken($token)->postJson($url, array_replace($payload, ['mq7_adc' => 1]))->assertConflict();
        $this->assertDatabaseCount('terra_readings', 1);
        $this->actingAs(User::factory()->create());
        $this->get('/terra?mode=simulation')->assertOk()->assertSee('TERRA simulation unique')->assertSee('SIMULASI')->assertSee('Pin ADC');
        $this->get('/terra')->assertOk()->assertDontSee('TERRA simulation unique');
        $this->getJson('/dashboard/snapshot')->assertJsonPath('total', 0)->assertJsonPath('terra.adc', []);
        $seen = $device->fresh()->last_seen_at;
        $this->travel(61)->seconds();
        $this->getJson('/dashboard/snapshot?mode=simulation')->assertOk()->assertJsonPath('devices.0.online', false);
        $this->assertTrue($seen->equalTo($device->fresh()->last_seen_at));
    }

    public function test_legacy_terra_payload_hash_is_preserved(): void
    {
        $device = Device::create(['name' => 'Legacy TERRA', 'type' => 'terra', 'is_simulated' => false]);
        $token = $device->rotateToken();
        $old = ['event_id' => 'legacy', 'captured_at' => '2026-10-02 04:00:00.000000', 'sensor_status' => null,
            'temperature_c' => null, 'thermocouple_ok' => null, 'mq7_adc' => null, 'mq7_voltage' => null, 'adc_resolution_bits' => null];
        ksort($old);
        TerraReading::create($old + ['device_id' => $device->id, 'received_at' => now(), 'is_simulated' => false, 'payload_hash' => hash('sha256', json_encode($old, JSON_PRESERVE_ZERO_FRACTION))]);
        $this->withToken($token)->postJson('/api/v1/terra/readings', ['reading_id' => 'legacy', 'captured_at' => '2026-10-02T04:00:00Z', 'sensor_status' => null])->assertOk();
        $this->assertDatabaseCount('terra_readings', 1);
    }

    public function test_api_errors_are_json_without_accept_header_and_identity_is_read_only(): void
    {
        $this->post('/api/v1/heartbeat', [], ['Authorization' => 'Bearer invalid-token'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $d = Device::create(['name' => 'Read-only identity', 'type' => 'binsonic', 'is_simulated' => true]);
        $token = $d->rotateToken();
        $this->withToken($token)->get('/api/v1/device')->assertOk()->assertJsonPath('type', 'binsonic')->assertJsonMissingPath('token_hash');
        $this->assertNull($d->fresh()->last_seen_at);
        $this->withToken($token)->post('/api/v1/binsonic/events', [])->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $other = Device::create(['name' => 'Other board', 'type' => 'binsonic', 'is_simulated' => false]);
        $this->withToken($token)->postJson('/api/v1/heartbeat', ['device_id' => $other->id])->assertUnprocessable();
        $this->assertNull($other->fresh()->last_seen_at);
        $this->assertNull($d->fresh()->last_seen_at);
    }

    public function test_admin_creation_and_cli_only_token_lifecycle(): void
    {
        $this->artisan('admin:create')->expectsQuestion('Nama administrator', 'Deployment admin')
            ->expectsQuestion('Email', 'deployment@example.test')->expectsQuestion('Kata sandi (minimal 12 karakter)', 'test-password-only')
            ->expectsQuestion('Ulangi kata sandi', 'test-password-only')->expectsOutput('Administrator dibuat.')->assertSuccessful();
        $this->assertTrue(Hash::check('test-password-only', User::where('email', 'deployment@example.test')->firstOrFail()->password));
        $this->artisan('device:create', ['name' => 'CLI SIM', 'type' => 'terra', '--simulated' => true])->assertSuccessful();
        $d = Device::where('name', 'CLI SIM')->firstOrFail();
        $this->assertTrue($d->is_simulated);
        $old = $d->token_hash;
        $this->assertNotNull($old);
        $this->artisan('device:token', ['device' => $d->id])->expectsConfirmation('Rotasi menonaktifkan token lama. Lanjutkan?', 'yes')->assertSuccessful();
        $this->assertNotSame($old, $d->fresh()->token_hash);
        $this->artisan('device:token', ['device' => $d->id, '--revoke' => true])->assertSuccessful();
        $this->assertNull($d->fresh()->token_hash);
        $this->artisan('device:create', ['name' => 'Wrong', 'type' => 'invalid'])->assertFailed();
        $this->artisan('device:token', ['device' => 99999999])->assertFailed();
    }
}
