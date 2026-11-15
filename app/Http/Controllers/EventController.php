<?php

namespace App\Http\Controllers;

use App\Models\EventDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventController
{
    public function ack(Request $r)
    {
        $data = $r->validate(['event_id' => 'required|uuid', 'delivery_id' => 'required|uuid', 'result' => 'required|in:APPLIED,STORED_FOR_REVIEW']);
        DB::transaction(function () use ($r, $data) {
            $delivery = EventDelivery::query()->where('device_id', $r->attributes->get('device')->id)->whereKey($data['delivery_id'])->where('event_id', $data['event_id'])->lockForUpdate()->firstOrFail();
            if (! $delivery->acknowledged_at) {
                $delivery->update(['acknowledged_at' => now(), 'ack_result' => $data['result']]);
            }
        });

        return response()->noContent();
    }

    public function resume(Request $r)
    {
        $deviceId = $r->attributes->get('device')->id;

        try {
            \App\Models\WebhookInbox::query()
                ->whereIn('state', ['PENDING', 'WAITING'])
                ->orderBy('updated_at')
                ->limit(20)
                ->pluck('id')
                ->each(fn ($id) => app(\App\Services\InboxProcessor::class)->process($id));
        } catch (\Throwable) {}

        EventDelivery::query()
            ->where('device_id', $deviceId)
            ->whereNull('acknowledged_at')
            ->update(['next_attempt_at' => now()]);

        try {
            EventDelivery::query()
                ->where('device_id', $deviceId)
                ->whereNull('acknowledged_at')
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->limit(20)
                ->pluck('id')
                ->each(fn ($id) => app(\App\Services\OutboxDispatcher::class)->dispatch($id));
        } catch (\Throwable) {}

        return response()->json(['accepted' => true], 202);
    }
}
