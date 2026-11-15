<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;

class CurrentDevice
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()->currentAccessToken();
        $device = Device::query()->where('user_id', $request->user()->id)->where('id', $token?->name)->whereNull('revoked_at')->first();
        abort_unless($device, 401);
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
