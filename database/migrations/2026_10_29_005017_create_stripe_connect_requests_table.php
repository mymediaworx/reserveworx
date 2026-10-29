<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_connect_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('stripe_accounts')->cascadeOnDelete();
            $table->string('idempotency_key', 80);
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'stripe_connect_requests_idempotency_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_connect_requests');
    }
};
