<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->uuidMorphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained();
            $t->uuid('installation_id')->unique();
            $t->text('push_token')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->unique()->constrained();
            $t->string('provider_id')->nullable()->unique();
            $t->string('status');
            $t->char('last_four', 4);
            $t->text('details');
            $t->string('idempotency_key', 128);
            $t->char('request_hash', 64);
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained();
            $t->foreignUuid('device_id')->constrained();
            $t->foreignUuid('bank_account_id')->constrained();
            $t->uuid('local_sale_id');
            $t->string('origin');
            $t->unsignedBigInteger('amount_minor');
            $t->string('method');
            $t->string('state');
            $t->string('idempotency_key', 128);
            $t->char('request_hash', 64);
            $t->string('provider_id')->nullable()->index();
            $t->text('creation_result')->nullable();
            $t->timestamp('abandoned_at')->nullable();
            $t->timestamps();
            $t->unique(['device_id', 'idempotency_key']);
        });
        Schema::create('webhook_inboxes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('webhook_id')->unique();
            $t->char('body_hash', 64);
            $t->string('event_type');
            $t->longText('payload');
            $t->string('state')->default('PENDING')->index();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('provider_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('transaction_id')->unique();
            $t->foreignUuid('payment_attempt_id')->constrained();
            $t->unsignedBigInteger('amount_minor');
            $t->string('association');
            $t->timestamp('paid_at');
            $t->timestamps();
        });
        Schema::create('event_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->foreignUuid('device_id')->constrained();
            $t->string('type');
            $t->text('payload');
            $t->timestamp('occurred_at');
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('ack_result')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->index();
            $t->timestamps();
            $t->unique(['event_id', 'device_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_deliveries', 'provider_transactions', 'webhook_inboxes', 'payment_attempts', 'bank_accounts', 'devices', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
