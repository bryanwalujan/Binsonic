<?php

use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:device-api'])->group(function () {
    Route::get('device', [TelemetryController::class, 'identity'])->middleware('device');
    Route::post('heartbeat', [TelemetryController::class, 'heartbeat'])->middleware('device');
    Route::post('binsonic/readings', [TelemetryController::class, 'readings'])->middleware('device:binsonic');
    Route::post('binsonic/events', [TelemetryController::class, 'store'])->middleware('device:binsonic');
    Route::post('terra/readings', [TelemetryController::class, 'store'])->middleware('device:terra');
    Route::post('binsonic/events/{event_id}/image', [TelemetryController::class, 'image'])->middleware('device:binsonic');
});
