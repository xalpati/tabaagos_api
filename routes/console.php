<?php

use App\Models\Device;
use App\Models\EventDelivery;
use App\Models\WebhookInbox;
use App\Services\InboxProcessor;
use App\Services\OutboxDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('events:dispatch', function () {
    WebhookInbox::query()->whereIn('state', ['PENDING', 'WAITING'])->orderBy('updated_at')->limit(100)->pluck('id')->each(function ($id) {
        try {
            app(InboxProcessor::class)->process($id);
        } catch (Throwable) {
            Log::warning('Inbox processing deferred', ['inbox_id' => $id]);
        }
    });
    EventDelivery::query()->whereNull('acknowledged_at')->where('next_attempt_at', '<=', now())->whereIn('device_id', Device::query()->select('id')->whereNull('revoked_at')->whereNotNull('push_token'))->orderBy('next_attempt_at')->limit(100)->pluck('id')->each(fn ($id) => app(OutboxDispatcher::class)->dispatch($id));
})->purpose('Procesar inbox y reenviar eventos sin acuse');
Schedule::command('events:dispatch')->everyMinute()->withoutOverlapping();
