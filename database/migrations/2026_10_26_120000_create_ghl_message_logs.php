<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghl_message_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('checkout_id')->nullable()->constrained('rental_checkouts')->nullOnDelete();
            $table->string('kind', 64);
            $table->string('channel', 16)->nullable();
            $table->string('dedupe_key', 191);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 255)->nullable();
            $table->string('location_id', 64)->nullable();
            $table->string('ghl_message_id', 64)->nullable();
            $table->unsignedInteger('offset_minutes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'ghl_message_logs_tenant_dedupe_uniq');
            $table->index(['tenant_id', 'created_at'], 'ghl_message_logs_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghl_message_logs');
    }
};
