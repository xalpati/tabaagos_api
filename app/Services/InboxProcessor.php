<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\EventDelivery;
use App\Models\PaymentAttempt;
use App\Models\ProviderTransaction;
use App\Models\WebhookInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboxProcessor
{
    public function process(string $id): void
    {
        DB::transaction(function () use ($id) {
            $inbox = WebhookInbox::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($inbox->state, ['PENDING', 'WAITING'], true)) {
                return;
            }
            $p = $inbox->payload;
            if ($inbox->event_type === 'bank.account.updated') {
                if (($p['ownerType'] ?? null) !== 'API_KEY' || (config('tabapagos.pey_key_id') && ($p['ownerId'] ?? null) !== config('tabapagos.pey_key_id')) || ($p['codiStatus'] ?? null) !== 'ACTIVE' || ! is_string($p['bankAccountId'] ?? null)) {
                    $inbox->update(['state' => 'REVIEW']);

                    return;
                }
                $bank = BankAccount::query()->where('provider_id', $p['bankAccountId'] ?? '')->lockForUpdate()->first();
                if (! $bank) {
                    $inbox->update(['state' => 'WAITING']);

                    return;
                }
                app(BankActivation::class)->activate($bank);
                $inbox->update(['state' => 'PROCESSED', 'processed_at' => now()]);

                return;
            }
            if (! in_array($inbox->event_type, ['codi.qr.payment_received', 'codi.pn.payment_received'], true)) {
                $inbox->update(['state' => 'UNSUPPORTED']);

                return;
            }
            $required = ['bankAccountId', 'transactionId', 'datePaid', 'amount'];
            foreach ($required as $key) {
                if (! isset($p[$key])) {
                    $inbox->update(['state' => 'REVIEW']);

                    return;
                }
            }
            if ((config('tabapagos.pey_key_id') && ($p['apiKeyId'] ?? null) !== config('tabapagos.pey_key_id')) || (isset($p['currency']) && $p['currency'] !== 'MXN') || ! is_string($p['transactionId']) || strlen($p['transactionId']) > 255 || ! is_int($p['datePaid']) || $p['datePaid'] < 0 || $p['datePaid'] > time() + 300 || ! is_string($p['bankAccountId']) || (! is_int($p['amount']) && ! is_float($p['amount']))) {
                $inbox->update(['state' => 'REVIEW']);

                return;
            }
            $money = (string) $p['amount'];
            if (! preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', $money, $parts)) {
                $inbox->update(['state' => 'REVIEW']);

                return;
            }
            $amount = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
            $attemptId = data_get($p, 'metadata.externalMetadata.payment_attempt_id');
            $query = PaymentAttempt::query();
            if (is_string($attemptId)) {
                $query->whereKey($attemptId);
            } else {
                $query->where('provider_id', $inbox->event_type === 'codi.qr.payment_received' ? ($p['codiQrId'] ?? '') : $p['transactionId']);
            }
            $attempt = $query->lockForUpdate()->first();
            if (! $attempt) {
                $inbox->update(['state' => 'WAITING']);

                return;
            }
            $bank = BankAccount::query()->findOrFail($attempt->bank_account_id);
            $providerResource = $inbox->event_type === 'codi.qr.payment_received' ? ($p['codiQrId'] ?? null) : $p['transactionId'];
            $expectedMethod = $inbox->event_type === 'codi.qr.payment_received' ? 'CODI_QR' : 'CODI_PUSH';
            if ($bank->provider_id !== $p['bankAccountId'] || $expectedMethod !== $attempt->method || ($attempt->provider_id && $attempt->provider_id !== $providerResource)) {
                $inbox->update(['state' => 'REVIEW']);

                return;
            }
            $existing = ProviderTransaction::query()->where('transaction_id', $p['transactionId'])->first();
            if ($existing) {
                $inbox->update(['state' => $existing->payment_attempt_id === $attempt->id && $existing->amount_minor === $amount ? 'PROCESSED' : 'REVIEW', 'processed_at' => now()]);

                return;
            }
            // La asociación nunca se infiere por importe u hora.
            $association = $amount === $attempt->amount_minor && ! $attempt->abandoned_at && ! in_array($attempt->state, ['PAID', 'REVIEW_REQUIRED'], true) && $p['datePaid'] <= $attempt->created_at->timestamp + 600 ? 'MATCHED' : 'REVIEW_REQUIRED';
            $paidAt = Carbon::createFromTimestampUTC($p['datePaid']);
            ProviderTransaction::query()->create(['transaction_id' => $p['transactionId'], 'payment_attempt_id' => $attempt->id, 'amount_minor' => $amount, 'association' => $association, 'paid_at' => $paidAt]);
            $attempt->update(['state' => $association === 'MATCHED' ? 'PAID' : 'REVIEW_REQUIRED']);
            EventDelivery::query()->create(['event_id' => (string) Str::uuid(), 'device_id' => $attempt->device_id, 'type' => 'payment.received', 'occurred_at' => $paidAt, 'payload' => ['payment_attempt_id' => $attempt->id, 'local_sale_id' => $attempt->local_sale_id, 'provider_transaction_id' => $p['transactionId'], 'amount_minor' => $amount, 'currency' => 'MXN', 'association' => $association], 'next_attempt_at' => now()]);
            $inbox->update(['state' => 'PROCESSED', 'processed_at' => now()]);
        }, 3);
    }
}
