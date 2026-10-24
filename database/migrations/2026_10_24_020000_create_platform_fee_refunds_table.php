<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('platform_fee_id')->constrained('platform_fees')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('reason', 500);
            $table->string('rule', 32);
            $table->string('stripe_fee_refund_id', 80)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'platform_fee_refunds_idempotency_uidx');
            $table->index(['tenant_id', 'platform_fee_id'], 'platform_fee_refunds_fee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_refunds');
    }
};
