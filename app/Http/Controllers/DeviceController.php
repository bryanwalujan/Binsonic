<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index()
    {
        return response()->view('devices', ['devices' => Device::when(app()->environment('production'), fn ($q) => $q->where('is_simulated', false))->orderBy('name')->get()])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'type' => 'required|in:binsonic,terra', 'is_simulated' => 'required|boolean']);
        $device = Device::create($data);

        return redirect('/devices')->with('message', 'Perangkat #'.$device->id.' dibuat. Terbitkan token melalui Terminal: php artisan device:token '.$device->id);
    }

    public function revoke(Device $device)
    {
        $device->update(['token_hash' => null]);

        return back()->with('message', 'Token dicabut.');
    }
}
