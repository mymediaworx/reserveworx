<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 64);
            $table->string('rules_version', 32);
            $table->unsignedInteger('customer_refund_cents');
            $table->unsignedInteger('customer_fee_refund_cents');
            $table->unsignedInteger('application_fee_refund_cents');
            $table->unsignedInteger('refund_subtotal_cents');
            $table->unsignedInteger('refund_tax_cents');
            $table->string('stripe_refund_id', 80)->nullable();
            $table->string('stripe_fee_refund_id', 80)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'refunds_idempotency_uidx');
            $table->index(['tenant_id', 'booking_id'], 'refunds_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
