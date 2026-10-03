<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terra_readings', function (Blueprint $table) {
            $table->dateTime('captured_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally keep nullable timestamps: reverting would discard valid unknown times.
    }
};
