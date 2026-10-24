<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->string('url', 2000);
            $table->char('url_hash', 64);
            $table->text('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'url_hash'], 'webhook_endpoints_url_uidx');
            $table->index(['tenant_id', 'active'], 'webhook_endpoints_active_idx');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('dedupe_key', 160)->nullable();
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'webhook_endpoint_id', 'dedupe_key'], 'webhook_deliveries_dedupe_uidx');
            $table->index(['tenant_id', 'created_at'], 'webhook_deliveries_created_idx');
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->text('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['webhook_delivery_id', 'attempt'], 'webhook_attempts_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
