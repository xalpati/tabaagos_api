<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apk_releases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('version_name', 30);
            $t->unsignedInteger('version_code');
            $t->string('package_name', 100);
            $t->unsignedSmallInteger('min_sdk')->default(24);
            $t->unsignedSmallInteger('target_sdk')->default(35);
            $t->unsignedBigInteger('file_size_bytes');
            $t->char('file_sha256', 64);
            $t->string('file_path');
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });

        Schema::create('download_registrations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 100);
            $t->string('email', 254)->index();
            $t->string('business_name', 150)->nullable();
            $t->string('phone', 20)->nullable();
            $t->boolean('privacy_acknowledged')->default(true);
            $t->string('privacy_notice_version', 20)->default('2026-11-15');
            $t->string('request_id', 128)->index();
            $t->char('payload_hash', 64);
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'payload_hash']);
        });

        Schema::create('download_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('download_registration_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('apk_release_id')->constrained()->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('downloaded_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('download_registrations');
        Schema::dropIfExists('apk_releases');
    }
};
