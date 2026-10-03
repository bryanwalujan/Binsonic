<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;

class DeviceToken
{
    public function handle(Request $request, Closure $next, ?string $type = null)
    {
        $token = $request->bearerToken();
        $device = $token ? Device::where('token_hash', hash('sha256', $token))->first() : null;
        abort_unless($device, 401, 'Token perangkat tidak valid.');
        abort_if($type && $device->type !== $type, 403, 'Jenis perangkat tidak diizinkan.');
        abort_if(app()->environment('production') && $device->is_simulated && ! $request->isMethod('GET') && ! $request->isMethod('HEAD'), 403, 'Pengiriman SIMULASI hanya di lingkungan pengujian.');
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
