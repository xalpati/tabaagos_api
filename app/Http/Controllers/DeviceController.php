<?php

namespace App\Http\Controllers;

use App\Models\EventDelivery;
use Illuminate\Http\Request;

class DeviceController
{
    public function update(Request $request)
    {
        $data = $request->validate(['push_token' => 'required|string|max:4096']);
        $device = $request->attributes->get('device');
        $device->update($data);
        EventDelivery::query()->where('device_id', $device->id)->whereNull('acknowledged_at')->update(['next_attempt_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $request)
    {
        $request->attributes->get('device')->update(['push_token' => null]);

        return response()->noContent();
    }
}
