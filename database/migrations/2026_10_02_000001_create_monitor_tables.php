<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits immediately; allow a failed migration to resume safely.
        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('type', 16);
                $t->boolean('is_simulated')->default(false);
                $t->string('token_hash', 64)->nullable()->unique();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }
        // MySQL DDL commits immediately; allow a failed migration to resume safely.
        if (! Schema::hasTable('binsonic_events')) {
            Schema::create('binsonic_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->constrained()->cascadeOnDelete();
                $t->string('event_id', 100);
                $t->dateTime('captured_at', 6);
                $t->dateTime('received_at', 6);
                $t->string('payload_hash', 64);
                foreach (['audio_label', 'vision_label', 'final_label', 'destination'] as $s) {
                    $t->string($s)->nullable();
                }
                foreach (['audio_confidence', 'vision_confidence', 'weight_g'] as $s) {
                    $t->double($s)->nullable();
                }
                $t->bigInteger('loadcell_raw')->nullable();
                $t->boolean('weight_calibrated');
                $t->unsignedInteger('moisture_adc')->nullable();
                $t->boolean('wet_contact')->nullable();
                $t->boolean('metal_detected')->nullable();
                $t->json('sensor_status')->nullable();
                $t->string('image_path')->nullable();
                $t->boolean('is_simulated');
                $t->unique(['device_id', 'event_id']);
                $t->index(['is_simulated', 'captured_at']);
            });
        }
        // MySQL DDL commits immediately; allow a failed migration to resume safely.
        if (! Schema::hasTable('terra_readings')) {
            Schema::create('terra_readings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->constrained()->cascadeOnDelete();
                $t->string('event_id', 100);
                $t->dateTime('captured_at', 6);
                $t->dateTime('received_at', 6);
                $t->string('payload_hash', 64);
                $t->double('temperature_c')->nullable();
                $t->boolean('thermocouple_ok')->nullable();
                $t->unsignedInteger('mq7_adc')->nullable();
                $t->double('mq7_voltage')->nullable();
                $t->unsignedTinyInteger('adc_resolution_bits')->nullable();
                $t->json('sensor_status')->nullable();
                $t->boolean('is_simulated');
                $t->unique(['device_id', 'event_id']);
                $t->index(['is_simulated', 'captured_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terra_readings');
        Schema::dropIfExists('binsonic_events');
        Schema::dropIfExists('devices');
    }
};
