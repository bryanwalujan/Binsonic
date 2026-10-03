<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\MonitorController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [MonitorController::class, 'dashboard']);
    Route::get('/dashboard/snapshot', [MonitorController::class, 'snapshot']);
    Route::get('/binsonic', [MonitorController::class, 'history']);
    Route::get('/binsonic/{event}', [MonitorController::class, 'detail']);
    Route::get('/binsonic/{event}/photo', [MonitorController::class, 'photo'])->name('photo');
    Route::get('/terra', [MonitorController::class, 'terra']);
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::post('/devices/{device}/revoke', [DeviceController::class, 'revoke']);
});
