<?php

namespace App\Services;

use App\Models\Device;
use App\Models\EventDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxDispatcher
{
    public function dispatch(string $id): void
    {
        Cache::lock('delivery:'.$id, 60)->get(function () use ($id) {
            $delivery = EventDelivery::query()->findOrFail($id);
            if ($delivery->acknowledged_at || $delivery->next_attempt_at->isFuture()) {
                return;
            }
            $device = Device::query()->findOrFail($delivery->device_id);
            if ($device->revoked_at || ! $device->push_token) {
                return;
            }
            $delivery->update(['attempts' => $delivery->attempts + 1, 'next_attempt_at' => now()->addSeconds(min(21600, 60 * 2 ** min(9, $delivery->attempts)))]);
            $envelope = ['schema_version' => 1, 'event_id' => $delivery->event_id, 'delivery_id' => $delivery->id, 'event_type' => $delivery->type, 'user_id' => $device->user_id, 'device_id' => $device->id, 'issued_at' => now()->timestamp, 'occurred_at' => $delivery->occurred_at->toIso8601String(), 'payload' => $delivery->payload];
            $token = $device->push_token;
            try {
                if (! app(PushSender::class)->send($token, $envelope)) {
                    DB::transaction(function () use ($device, $token) {
                        $current = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
                        if ($current->push_token === $token) {
                            $current->update(['push_token' => null]);
                        }
                    });
                }
            } catch (\Throwable) {
                // El próximo intento ya quedó persistido; no confirmar ni descartar el evento.
                Log::warning('Event delivery deferred', ['delivery_id' => $id]);
            }
        });
    }
}
