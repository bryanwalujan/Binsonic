<?php

namespace Tests\Feature;

use App\Models\BinsonicEvent;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use DatabaseTransactions;

    private function device(string $type = 'binsonic', bool $sim = false): array
    {
        $d = Device::create(['name' => 'Uji '.$type, 'type' => $type, 'is_simulated' => $sim]);

        return [$d, $d->rotateToken()];
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['event_id' => 'evt-1', 'captured_at' => '2026-10-02T12:00:00+08:00', 'sensor_status' => null, 'weight_calibrated' => false], $extra);
    }

    private function send(string $token, array $payload)
    {
        return $this->withToken($token)->postJson('/api/v1/binsonic/events', $payload);
    }

    public function test_web_requires_login_and_has_no_registration(): void
    {
        foreach (['/dashboard', '/binsonic', '/terra', '/devices', '/dashboard/snapshot'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_token_authentication_type_and_revocation(): void
    {
        $this->postJson('/api/v1/heartbeat')->assertUnauthorized();
        [$d,$token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/terra/readings', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/heartbeat', [])->assertOk();
        $this->assertNotNull($d->fresh()->last_seen_at);
        $this->assertNotEquals($token, $d->fresh()->token_hash);
        $new = $d->rotateToken();
        $this->withToken($token)->postJson('/api/v1/heartbeat')->assertUnauthorized();
        $this->withToken($new)->postJson('/api/v1/heartbeat')->assertOk();
        $d->update(['token_hash' => null]);
        $this->withToken($new)->postJson('/api/v1/heartbeat')->assertUnauthorized();
    }

    public function test_nullable_classification_utc_and_server_identity(): void
    {
        [$d,$t] = $this->device();
        $this->send($t, $this->payload())->assertCreated()->assertJsonPath('data.weight_g', null)->assertJsonPath('data.wet_contact', null)->assertJsonPath('data.is_simulated', false);
        $e = BinsonicEvent::first();
        $this->assertSame('2026-10-02 04:00:00', $e->captured_at->format('Y-m-d H:i:s'));
        $this->assertNull($e->final_label);
        $this->assertSame($d->id, $e->device_id);
        foreach (['device_id' => 999, 'is_simulated' => true, 'image_url' => 'https://example.com/image.jpg', 'received_at' => '2026-10-02'] as $key => $value) {
            $this->send($t, $this->payload([$key => $value]))->assertUnprocessable();
        }
    }

    public function test_validation_and_uncalibrated_weight(): void
    {
        [$d,$t] = $this->device();
        foreach ([['audio_confidence' => 1.1], ['vision_confidence' => -0.1], ['destination' => 'safe_burn'], ['weight_g' => 12], ['wet_contact' => 20], ['captured_at' => '2026-10-02 12:00:00'], ['sensor_status' => ['metal' => 'something']]] as $extra) {
            $this->send($t, $this->payload($extra))->assertUnprocessable();
        }
        $this->assertNull($d->fresh()->last_seen_at);
        $this->send($t, $this->payload(['weight_calibrated' => true, 'weight_g' => 0, 'wet_contact' => false, 'metal_detected' => null]))->assertCreated()->assertJsonPath('data.weight_g', 0)->assertJsonPath('data.wet_contact', false)->assertJsonPath('data.metal_detected', null);
    }

    public function test_idempotency_conflict_and_unique_constraint(): void
    {
        [$d,$t] = $this->device();
        $p = $this->payload();
        $first = $this->send($t, $p)->assertCreated()->json('data');
        $this->send($t, $p)->assertOk()->assertExactJson(['data' => $first]);
        $this->send($t, $this->payload(['audio_label' => null]))->assertOk();
        $this->send($t, $this->payload(['final_label' => 'different']))->assertConflict();
        $this->assertDatabaseCount('binsonic_events', 1);
        [$other,$token] = $this->device();
        $this->send($token, $p)->assertCreated();
        $this->assertDatabaseCount('binsonic_events', 2);
        $this->expectException(QueryException::class);
        BinsonicEvent::first()->replicate()->save();
    }

    public function test_terra_nullable_validation_and_idempotency(): void
    {
        [$d,$t] = $this->device('terra', true);
        $payload = ['event_id' => 'reading', 'captured_at' => '2026-10-02T04:00:00Z', 'sensor_status' => ['mq7' => 'unavailable'], 'temperature_c' => null, 'thermocouple_ok' => null, 'mq7_adc' => null];
        $this->withToken($t)->postJson('/api/v1/terra/readings', $payload)->assertCreated()->assertJsonPath('data.temperature_c', null)->assertJsonPath('data.is_simulated', true);
        $this->withToken($t)->postJson('/api/v1/terra/readings', $payload)->assertOk();
        $this->withToken($t)->postJson('/api/v1/terra/readings', array_replace($payload, ['mq7_adc' => 12]))->assertConflict();
        $this->withToken($t)->postJson('/api/v1/terra/readings', array_replace($payload, ['mq7_adc' => 1024, 'adc_resolution_bits' => 10]))->assertUnprocessable();
        $this->withToken($t)->postJson('/api/v1/binsonic/events', $this->payload())->assertForbidden();
    }

    public function test_private_photo_ownership_real_file_validation_and_size(): void
    {
        Storage::fake('local');
        [$d,$t] = $this->device();
        [$other,$otherToken] = $this->device();
        $this->send($t, $this->payload())->assertCreated();
        $url = '/api/v1/binsonic/events/evt-1/image';
        $this->withToken($otherToken)->postJson($url, ['image' => UploadedFile::fake()->image('photo.jpg')])->assertNotFound();
        $this->withToken($t)->postJson($url, ['image' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')])->assertUnprocessable();
        $this->withToken($t)->postJson($url, ['image' => UploadedFile::fake()->image('big.jpg')->size(5121)])->assertUnprocessable();
        $this->withToken($t)->postJson($url, ['image' => UploadedFile::fake()->image('valid.png')])->assertCreated();
        $event = BinsonicEvent::first();
        Storage::disk('local')->assertExists($event->image_path);
        $this->withToken($t)->postJson($url, ['image' => UploadedFile::fake()->image('again.png')])->assertConflict();
        $this->get('/binsonic/'.$event->id.'/photo')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/binsonic/'.$event->id.'/photo')->assertOk();
    }

    public function test_simulation_statistics_and_pages_are_separated(): void
    {
        [$real,$rt] = $this->device();
        [$sim,$st] = $this->device('binsonic', true);
        $this->send($rt, $this->payload(['destination' => 'organic']))->assertCreated();
        $this->send($st, $this->payload(['destination' => 'recycle']))->assertCreated();
        $this->actingAs(User::factory()->create());
        $this->getJson('/dashboard/snapshot')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('counts.organic', 1)->assertJsonMissingPath('counts.recycle');
        $this->getJson('/dashboard/snapshot?mode=simulation')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('counts.recycle', 1)->assertJsonMissingPath('counts.organic');
        foreach (['/dashboard', '/binsonic', '/binsonic/'.BinsonicEvent::firstOrFail()->id, '/terra', '/devices', '/dashboard?mode=simulation'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/binsonic?destination=recycle')->assertDontSee('Detail →');
    }

    public function test_heartbeat_does_not_make_old_sensor_data_fresh(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3));
        [$d,$t] = $this->device();
        $this->send($t, $this->payload())->assertCreated();
        $this->actingAs(User::factory()->create())->getJson('/dashboard/snapshot')->assertJsonPath('devices.0.online', true)->assertJsonPath('devices.0.sensor', 'Data kedaluwarsa');
        $this->travel(61)->seconds();
        $this->getJson('/dashboard/snapshot')->assertJsonPath('devices.0.online', false);
    }

    public function test_admin_login_and_device_token_management(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->post('/login', ['email' => $user->email, 'password' => 'a-long-test-password'])->assertRedirect('/dashboard');
        $this->post('/devices', ['name' => 'Lab', 'type' => 'binsonic', 'is_simulated' => true])->assertRedirect('/devices')->assertSessionMissing('token');
        $d = Device::first();
        $this->assertNull($d->token_hash);
        $token = $d->rotateToken();
        $this->get('/devices')->assertOk()->assertDontSee($token)->assertDontSee($d->fresh()->token_hash);
        $this->post('/devices/'.$d->id.'/rotate')->assertNotFound();
        $this->post('/devices/'.$d->id.'/revoke')->assertRedirect();
        $this->assertNull($d->fresh()->token_hash);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_api_rate_limit(): void
    {
        [$d,$t] = $this->device();
        for ($i = 0; $i < 120; $i++) {
            $this->withToken($t)->postJson('/api/v1/heartbeat')->assertOk();
        }
        $this->withToken($t)->postJson('/api/v1/heartbeat')->assertStatus(429);
    }

    public function test_timestamp_fraction_changes_are_conflicts(): void
    {
        [$d, $t] = $this->device();
        $this->send($t, $this->payload(['captured_at' => '2026-10-02T04:00:00.123456Z']))->assertCreated();
        $this->send($t, $this->payload(['captured_at' => '2026-10-02T12:00:00.123456+08:00']))->assertOk();
        $this->send($t, $this->payload(['captured_at' => '2026-10-02T04:00:00.123457Z']))->assertConflict();
    }
}
