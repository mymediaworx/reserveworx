<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->string('payment_status', 32);
            $table->string('operational_status', 32);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('participants');
            $table->string('idempotency_key', 80)->nullable();
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'bookings_idempotency_uidx');
            $table->index(['tenant_id', 'starts_at', 'ends_at'], 'bookings_interval_idx');
            $table->index(['tenant_id', 'status'], 'bookings_status_idx');
        });

        Schema::create('booking_line_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 32);
            $table->ulid('resource_id');
            $table->unsignedInteger('quantity');
            $table->dateTime('buffered_starts_at');
            $table->dateTime('buffered_ends_at');
            $table->string('description');
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'line_items_booking_idx');
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('pool_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id', 'starts_at', 'ends_at'], 'alloc_asset_interval_idx');
            $table->index(['tenant_id', 'pool_id', 'starts_at', 'ends_at'], 'alloc_pool_interval_idx');
        });

        Schema::create('holds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('pool_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id', 'expires_at'], 'holds_asset_expiry_idx');
            $table->index(['tenant_id', 'pool_id', 'expires_at'], 'holds_pool_expiry_idx');
            $table->index('expires_at', 'holds_expires_idx');
        });

        Schema::create('capacity_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pool_id')->constrained()->cascadeOnDelete();
            $table->dateTime('bucket_start_utc');
            $table->unsignedInteger('allocated_units')->default(0);
            $table->unsignedInteger('held_units')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'pool_id', 'bucket_start_utc'], 'ledger_bucket_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_ledger');
        Schema::dropIfExists('holds');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('booking_line_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('customers');
    }
};
