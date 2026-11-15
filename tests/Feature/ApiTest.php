<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Device;
use App\Models\EventDelivery;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\WebhookInbox;
use App\Services\BankActivation;
use App\Services\GoogleIdentity;
use App\Services\InboxProcessor;
use App\Services\OutboxDispatcher;
use App\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use StandardWebhooks\Webhook;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function deviceSession(): array
    {
        $user = User::query()->create(['google_sub' => (string) Str::uuid(), 'email' => 'test@example.test']);
        $device = Device::query()->create(['user_id' => $user->id, 'installation_id' => (string) Str::uuid()]);
        $token = $user->createToken($device->id, ['mobile'])->plainTextToken;

        return [$user, $device, ['Authorization' => 'Bearer '.$token]];
    }

    private function bank(User $user, string $status = 'ACTIVE'): BankAccount
    {
        return BankAccount::query()->create(['user_id' => $user->id, 'status' => $status, 'provider_id' => (string) Str::uuid(), 'details' => ['clabe' => '032180000118359719'], 'last_four' => '9719', 'idempotency_key' => 'bank-key-123', 'request_hash' => str_repeat('a', 64)]);
    }

    private function attempt(User $user, Device $device): PaymentAttempt
    {
        $bank = $this->bank($user);

        return PaymentAttempt::query()->create(['user_id' => $user->id, 'device_id' => $device->id, 'bank_account_id' => $bank->id, 'local_sale_id' => (string) Str::uuid(), 'origin' => 'DIRECT', 'amount_minor' => 15000, 'method' => 'CODI_QR', 'state' => 'AWAITING_PAYMENT', 'provider_id' => 'qr-1', 'idempotency_key' => 'request-123', 'request_hash' => str_repeat('b', 64)]);
    }

    private function event(PaymentAttempt $a, array $overrides = []): array
    {
        return array_replace_recursive(['type' => 'codi.qr.payment_received', 'apiKeyId' => 'test-key', 'bankAccountId' => BankAccount::find($a->bank_account_id)->provider_id, 'codiQrId' => 'qr-1', 'transactionId' => 'txn-1', 'amount' => 150, 'datePaid' => time(), 'metadata' => ['externalMetadata' => ['payment_attempt_id' => $a->id]]], $overrides);
    }

    private function webhook(array $payload, string $id = 'webhook-1', ?int $time = null)
    {
        config(['tabapagos.webhook_secret' => 'whsec_'.base64_encode('test-secret'), 'tabapagos.pey_key_id' => 'test-key']);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $time ??= time();
        $sig = (new Webhook(config('tabapagos.webhook_secret')))->sign($id, $time, $body);

        return $this->call('POST', '/api/webhooks/pey', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => (string) $time, 'HTTP_WEBHOOK_SIGNATURE' => $sig], $body);
    }

    private function process(): void
    {
        WebhookInbox::query()->pluck('id')->each(fn ($id) => app(InboxProcessor::class)->process($id));
    }

    public function test_routes_require_a_device_session(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_uses_verified_google_sub_and_rotates_session(): void
    {
        $this->mock(GoogleIdentity::class)->shouldReceive('verify')->twice()->andReturn(['sub' => 'google-verified', 'email' => 'test@example.test']);
        $input = ['id_token' => 'test-id-token', 'installation_id' => (string) Str::uuid()];
        $token = $this->postJson('/api/v1/auth/google', $input)->assertOk()->json('access_token');
        $this->postJson('/api/v1/auth/google', $input)->assertOk();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_google_token_does_not_create_user(): void
    {
        config(['tabapagos.google_client_id' => 'test-client']);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'invalid', 'installation_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_installation_cannot_change_owner(): void
    {
        [, $d] = $this->deviceSession();
        $this->mock(GoogleIdentity::class)->shouldReceive('verify')->andReturn(['sub' => 'another-user']);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'verified-test-token', 'installation_id' => $d->installation_id])->assertConflict();
    }

    public function test_logout_revokes_session_and_push_destination(): void
    {
        [, $d,$h] = $this->deviceSession();
        $d->update(['push_token' => 'private-token']);
        $this->postJson('/api/v1/auth/logout', [], $h)->assertNoContent();
        $this->getJson('/api/v1/me', $h)->assertUnauthorized();
        $this->assertNull($d->fresh()->push_token);
    }

    public function test_push_token_is_encrypted(): void
    {
        [, $d,$h] = $this->deviceSession();
        $this->putJson('/api/v1/devices/current/push-token', ['push_token' => 'private-fcm-token'], $h)->assertNoContent();
        $this->assertNotSame('private-fcm-token', $d->fresh()->getRawOriginal('push_token'));
    }

    public function test_bank_active_refresh_makes_no_provider_request(): void
    {
        [$u,,$h] = $this->deviceSession();
        $this->bank($u);
        Http::preventStrayRequests();
        $this->postJson('/api/v1/bank-account/refresh', [], $h)->assertOk()->assertJsonPath('status', 'ACTIVE');
        Http::assertNothingSent();
    }

    public function test_bank_query_cannot_degrade_active_webhook(): void
    {
        [$u,$d,$h] = $this->deviceSession();
        $bank = $this->bank($u, 'PENDING');
        config(['tabapagos.pey_enabled' => true, 'tabapagos.pey_key' => 'test', 'tabapagos.pey_key_id' => 'test-key']);
        Http::fake(function () use ($bank) {
            DB::transaction(function () use ($bank) {
                app(BankActivation::class)->activate($bank);
            });

            return Http::response(['id' => $bank->provider_id, 'codiStatus' => 'PENDING']);
        });
        $this->postJson('/api/v1/bank-account/refresh', [], $h)->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertDatabaseCount('event_deliveries', 1);
    }

    public function test_creating_bank_is_idempotent_and_masks_clabe(): void
    {
        [,,$h] = $this->deviceSession();
        config(['tabapagos.pey_enabled' => true, 'tabapagos.pey_key' => 'test', 'tabapagos.pey_key_id' => 'test-key']);
        $digits = '03218000011835971';
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += ((int) $digits[$i] * [3, 7, 1][$i % 3]) % 10;
        }
        $body = ['alias' => 'Cuenta', 'name' => 'Nombre de prueba', 'clabe' => $digits.((10 - $sum % 10) % 10), 'entity' => 'PERSON'];
        Http::fake(['api.pey.mx/*' => Http::response(['id' => 'bank-1', 'codiStatus' => 'PENDING'])]);
        $h['Idempotency-Key'] = 'bank-key-123';
        $this->postJson('/api/v1/bank-account', $body, $h)->assertCreated()->assertJsonMissing(['clabe' => $body['clabe']]);
        $this->postJson('/api/v1/bank-account', $body, $h)->assertOk();
        Http::assertSentCount(1);
        $body['alias'] = 'Otro';
        $this->postJson('/api/v1/bank-account', $body, $h)->assertConflict();
    }

    public function test_payment_retry_does_not_repeat_provider_post(): void
    {
        [$u,,$h] = $this->deviceSession();
        $this->bank($u);
        config(['tabapagos.pey_enabled' => true, 'tabapagos.pey_key' => 'test', 'tabapagos.pey_key_id' => 'test-key']);
        Http::fake(['api.pey.mx/*' => Http::response(['id' => 'qr-1', 'qrString' => 'example-qr'], 201)]);
        $body = ['local_sale_id' => (string) Str::uuid(), 'origin' => 'DIRECT', 'amount_minor' => 15000, 'method' => 'CODI_QR', 'qr_mode' => 'per_sale', 'concept' => 'Venta'];
        $h['Idempotency-Key'] = 'request-123';
        $first = $this->postJson('/api/v1/payment-attempts', $body, $h)->assertCreated()->assertJsonPath('state', 'AWAITING_PAYMENT')->json('id');
        $this->postJson('/api/v1/payment-attempts', $body, $h)->assertOk()->assertJsonPath('id', $first);
        Http::assertSentCount(1);
        $body['amount_minor'] = 20000;
        $this->postJson('/api/v1/payment-attempts', $body, $h)->assertConflict();
    }

    public function test_lost_provider_response_remains_unknown_without_retry(): void
    {
        [$u,,$h] = $this->deviceSession();
        $this->bank($u);
        config(['tabapagos.pey_enabled' => true, 'tabapagos.pey_key' => 'test', 'tabapagos.pey_key_id' => 'test-key']);
        Http::fake(['api.pey.mx/*' => Http::failedConnection()]);
        $body = ['local_sale_id' => (string) Str::uuid(), 'origin' => 'DIRECT', 'amount_minor' => 15000, 'method' => 'CODI_QR', 'qr_mode' => 'per_sale', 'concept' => 'Venta'];
        $h['Idempotency-Key'] = 'request-123';
        $this->postJson('/api/v1/payment-attempts', $body, $h)->assertCreated()->assertJsonPath('state', 'UNKNOWN');
        $this->postJson('/api/v1/payment-attempts', $body, $h)->assertOk()->assertJsonPath('state', 'UNKNOWN');
        Http::assertSentCount(1);
    }

    public function test_no_payment_verification_get_endpoints_exist(): void
    {
        [,,$h] = $this->deviceSession();
        $this->getJson('/api/v1/payment-attempts/'.Str::uuid(), $h)->assertNotFound();
        $this->getJson('/api/v1/events', $h)->assertNotFound();
    }

    public function test_webhook_deduplication_applies_money_and_event_once(): void
    {
        [$u,$d] = $this->deviceSession();
        $a = $this->attempt($u, $d);
        $p = $this->event($a);
        $this->webhook($p)->assertAccepted();
        $this->webhook($p)->assertAccepted();
        $this->process();
        $this->webhook($p, 'different-webhook-id')->assertAccepted();
        $this->process();
        $this->assertDatabaseCount('provider_transactions', 1);
        $this->assertDatabaseCount('event_deliveries', 1);
        $this->assertSame('PAID', $a->fresh()->state);
    }

    public function test_bad_signature_and_old_webhook_are_rejected(): void
    {
        config(['tabapagos.webhook_secret' => 'whsec_'.base64_encode('test-secret')]);
        $this->postJson('/api/webhooks/pey', ['type' => 'test'])->assertUnauthorized();
        $this->webhook(['type' => 'test'], 'expired', time() - 601)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_inboxes', 0);
    }

    public function test_reused_webhook_id_with_different_body_conflicts(): void
    {
        $this->webhook(['type' => 'test'])->assertAccepted();
        $this->webhook(['type' => 'other'])->assertConflict();
    }

    public function test_wrong_bank_webhook_is_quarantined(): void
    {
        [$u,$d] = $this->deviceSession();
        $a = $this->attempt($u, $d);
        $this->webhook($this->event($a, ['bankAccountId' => 'foreign']))->assertAccepted();
        $this->process();
        $this->assertDatabaseCount('provider_transactions', 0);
        $this->assertDatabaseHas('webhook_inboxes', ['state' => 'REVIEW']);
    }

    public function test_early_webhook_waits_for_relationship_without_get(): void
    {
        [$u,$d] = $this->deviceSession();
        $a = $this->attempt($u, $d);
        $p = $this->event($a);
        unset($p['metadata']);
        $a->update(['provider_id' => null]);
        Http::preventStrayRequests();
        $this->webhook($p)->assertAccepted();
        $this->process();
        $this->assertDatabaseHas('webhook_inboxes', ['state' => 'WAITING']);
        $a->update(['provider_id' => 'qr-1']);
        $this->process();
        $this->assertSame('PAID', $a->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_late_and_additional_money_is_preserved_for_review(): void
    {
        [$u,$d] = $this->deviceSession();
        $a = $this->attempt($u, $d);
        $a->update(['state' => 'CANCELLED_LOCAL', 'abandoned_at' => now()]);
        $this->webhook($this->event($a))->assertAccepted();
        $this->process();
        $this->webhook($this->event($a, ['transactionId' => 'txn-2', 'amount' => 100]), 'second-payment')->assertAccepted();
        $this->process();
        $this->assertDatabaseCount('provider_transactions', 2);
        $this->assertDatabaseCount('event_deliveries', 2);
        $this->assertSame('REVIEW_REQUIRED', $a->fresh()->state);
    }

    public function test_ack_is_recipient_only_idempotent_and_stops_retry(): void
    {
        [$u,$d,$h] = $this->deviceSession();
        $a = $this->attempt($u, $d);
        $this->webhook($this->event($a));
        $this->process();
        $delivery = EventDelivery::first();
        [,,$other] = $this->deviceSession();
        $body = ['event_id' => $delivery->event_id, 'delivery_id' => $delivery->id, 'result' => 'APPLIED'];
        $this->postJson('/api/v1/events/ack', $body, $other)->assertNotFound();
        $this->postJson('/api/v1/events/ack', $body, $h)->assertNoContent();
        $this->postJson('/api/v1/events/ack', $body, $h)->assertNoContent();
        $this->mock(PushSender::class)->shouldNotReceive('send');
        app(OutboxDispatcher::class)->dispatch($delivery->id);
    }

    public function test_resume_returns_only_acceptance_and_replays_same_delivery(): void
    {
        [$u,$d,$h] = $this->deviceSession();
        $d->update(['push_token' => 'test-fcm']);
        $a = $this->attempt($u, $d);
        $this->webhook($this->event($a));
        $this->process();
        $delivery = EventDelivery::first();
        $this->mock(PushSender::class)->shouldReceive('send')->twice()->withArgs(fn ($token, $envelope) => $envelope['delivery_id'] === $delivery->id && $envelope['payload']['amount_minor'] === 15000)->andReturn(true);
        app(OutboxDispatcher::class)->dispatch($delivery->id);
        $this->postJson('/api/v1/events/resume', [], $h)->assertAccepted()->assertExactJson(['accepted' => true]);
        app(OutboxDispatcher::class)->dispatch($delivery->id);
        $this->assertNull($delivery->fresh()->acknowledged_at);
    }

    public function test_wrong_device_cannot_abandon_attempt(): void
    {
        [$u,$d] = $this->deviceSession();
        $a = $this->attempt($u,$d);
        [,,$h] = $this->deviceSession();
        $this->postJson('/api/v1/payment-attempts/'.$a->id.'/abandon',[],$h)->assertNotFound();
    }
}
