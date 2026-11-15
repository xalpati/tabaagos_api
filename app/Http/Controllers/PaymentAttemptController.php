<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Device;
use App\Models\PaymentAttempt;
use App\Services\Idempotency;
use App\Services\PeyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentAttemptController
{
    public function store(Request $r, PeyClient $pey)
    {
        $data = $r->validate(['local_sale_id' => 'required|uuid', 'origin' => 'required|in:CATALOG,DIRECT', 'amount_minor' => 'required|integer|min:1|max:100000000', 'method' => 'required|in:CODI_QR,CODI_PUSH', 'concept' => 'required|string|max:255', 'phone' => ['required_if:method,CODI_PUSH', 'prohibited_unless:method,CODI_PUSH', 'regex:/^[0-9]{10}$/D'], 'qr_mode' => 'required_if:method,CODI_QR|prohibited_unless:method,CODI_QR|in:per_sale']);
        $key = Idempotency::key($r);
        $hash = Idempotency::hash($data);
        $device = $r->attributes->get('device');
        $pey->available();
        [$attempt,$created] = DB::transaction(function () use ($r, $device, $data, $key, $hash) {
            Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $prior = PaymentAttempt::query()->where('device_id', $device->id)->where('idempotency_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $hash), 409);

                return [$prior, false];
            }
            $bank = BankAccount::query()->where('user_id', $r->user()->id)->where('status', 'ACTIVE')->whereNotNull('provider_id')->first();
            abort_unless($bank, 409);

            return [PaymentAttempt::query()->create(['user_id' => $r->user()->id, 'device_id' => $device->id, 'bank_account_id' => $bank->id, 'local_sale_id' => $data['local_sale_id'], 'origin' => $data['origin'], 'amount_minor' => $data['amount_minor'], 'method' => $data['method'], 'state' => 'SUBMITTING', 'idempotency_key' => $key, 'request_hash' => $hash]), true];
        });
        if ($created) {
            $bank = BankAccount::query()->findOrFail($attempt->bank_account_id);
            $body = ['amount' => $data['amount_minor'] / 100, 'concept' => $data['concept'], 'expiration' => 600, 'bankAccountId' => $bank->provider_id, 'metadata' => ['payment_attempt_id' => $attempt->id, 'device_id' => $device->id, 'local_sale_id' => $data['local_sale_id']]];
            if ($data['method'] === 'CODI_PUSH') {
                $body['phoneNumber'] = $data['phone'];
            } else {
                $body['generateImage'] = false;
            }
            try {
                $result = $pey->call('POST', $data['method'] === 'CODI_QR' ? '/codi/qr' : '/codi/pn', $body);
                $providerId = $data['method'] === 'CODI_QR' ? ($result['id'] ?? null) : ($result['transactionId'] ?? null);
                if (! is_string($providerId) || ($data['method'] === 'CODI_QR' && ! is_string($result['qrString'] ?? null))) {
                    throw new \RuntimeException('INVALID_RESPONSE');
                }
                DB::transaction(function () use ($attempt, $providerId, $result) {
                    $current = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                    $current->update(['provider_id' => $providerId, 'creation_result' => array_intersect_key($result, array_flip(['id', 'qrString', 'transactionId', 'codiId'])), 'state' => in_array($current->state, ['PAID', 'REVIEW_REQUIRED'], true) ? $current->state : 'AWAITING_PAYMENT']);
                });
            } catch (\Throwable) {
                PaymentAttempt::query()->whereKey($attempt->id)->where('state', 'SUBMITTING')->update(['state' => 'UNKNOWN']);
            }
        }
        $attempt->refresh();

        return response()->json(['id' => $attempt->id, 'state' => $attempt->state, 'creation' => $attempt->creation_result], $created ? 201 : 200);
    }

    public function abandon(Request $r, string $id)
    {
        $device = $r->attributes->get('device');
        DB::transaction(function () use ($device, $id) {
            $attempt = PaymentAttempt::query()->where('device_id', $device->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($attempt->state, ['PAID', 'REVIEW_REQUIRED'], true)) {
                $attempt->update(['state' => 'CANCELLED_LOCAL', 'abandoned_at' => now()]);
            }
        });

        return response()->noContent();
    }
}
