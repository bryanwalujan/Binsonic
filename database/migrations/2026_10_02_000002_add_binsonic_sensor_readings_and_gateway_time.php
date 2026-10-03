<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('binsonic_readings')) {
            Schema::create('binsonic_readings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->constrained()->cascadeOnDelete();
                $t->string('event_id', 100);
                $t->dateTime('captured_at', 6)->nullable();
                $t->dateTime('gateway_received_at', 6)->nullable();
                $t->dateTime('received_at', 6);
                $t->string('payload_hash', 64);
                $t->bigInteger('loadcell_raw')->nullable();
                $t->boolean('weight_calibrated');
                $t->double('weight_g')->nullable();
                $t->unsignedInteger('moisture_adc')->nullable();
                $t->boolean('wet_contact')->nullable();
                $t->boolean('metal_detected')->nullable();
                $t->json('sensor_status')->nullable();
                $t->boolean('is_simulated');
                $t->unique(['device_id', 'event_id']);
                $t->index(['is_simulated', 'received_at']);
            });
        }
        if (! Schema::hasColumn('binsonic_events', 'gateway_received_at')) {
            Schema::table('binsonic_events', function (Blueprint $t) {
                $t->dateTime('gateway_received_at', 6)->nullable();
            });
        }
        Schema::table('binsonic_events', function (Blueprint $t) {
            $t->dateTime('captured_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Retain nullable captured_at: existing events without a board clock must not be fabricated.
        Schema::table('binsonic_events', function (Blueprint $t) {
            $t->dropColumn('gateway_received_at');
        });
        Schema::dropIfExists('binsonic_readings');
    }
};
