<?php

namespace App\Http\Controllers;

use App\Models\WebhookInbox;
use Illuminate\Http\Request;
use StandardWebhooks\Webhook;

class WebhookController
{
    public function __invoke(Request $r)
    {
        abort_if(strlen($r->getContent()) > 65536, 413);
        $secret = config('tabapagos.webhook_secret');
        abort_unless($secret, 503);
        $headers = [];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
            $headers[$header] = $r->header($header);
            abort_unless(is_string($headers[$header]) && strlen($headers[$header]) <= 2048, 401);
        }
        abort_unless(strlen($headers['webhook-id']) <= 255 && preg_match('/^[0-9]+$/D', $headers['webhook-timestamp']) && preg_match('/^v1,[A-Za-z0-9+\/=]+(?: v[0-9]+,[A-Za-z0-9+\/=]+)*$/D', $headers['webhook-signature']), 401);
        try {
            $data = (new Webhook($secret))->verify($r->getContent(), $headers);
        } catch (\Throwable) {
            abort(401);
        }
        abort_unless(is_array($data) && is_string($data['type'] ?? null) && strlen($data['type']) <= 255, 422);
        $hash = hash('sha256', $r->getContent());
        $inbox = WebhookInbox::query()->firstOrCreate(['webhook_id' => $headers['webhook-id']], ['body_hash' => $hash, 'event_type' => $data['type'], 'payload' => $data, 'state' => 'PENDING']);
        abort_unless(hash_equals($inbox->body_hash, $hash), 409);

        // Procesar el inbox y despachar el evento push de inmediato tras persistir
        try {
            app(\App\Services\InboxProcessor::class)->process($inbox->id);
            \App\Models\EventDelivery::query()
                ->whereNull('acknowledged_at')
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->limit(10)
                ->pluck('id')
                ->each(fn ($id) => app(\App\Services\OutboxDispatcher::class)->dispatch($id));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Webhook immediate dispatch deferred: ' . $e->getMessage());
        }

        // El ACK del proveedor solo depende de la persistencia, no de FCM.
        return response()->json(['accepted' => true], 202);
    }
}
