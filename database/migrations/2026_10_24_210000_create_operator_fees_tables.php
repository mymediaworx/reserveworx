<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('pricing', 32);
            $table->unsignedInteger('amount_cents')->nullable();
            $table->unsignedSmallInteger('percent_bps')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'operator_fees_name_uidx');
            $table->index(['tenant_id', 'active'], 'operator_fees_active_idx');
            $table->index(['tenant_id', 'service_id'], 'operator_fees_service_idx');
            $table->index(['tenant_id', 'location_id'], 'operator_fees_location_idx');
        });

        Schema::create('booking_operator_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('operator_fee_id')->constrained('operator_fees')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('scope', 32);
            $table->string('pricing', 32);
            $table->unsignedInteger('amount_cents')->nullable();
            $table->unsignedSmallInteger('percent_bps')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('total_cents');
            $table->timestamps();

            $table->unique(['booking_id', 'operator_fee_id'], 'booking_operator_fees_choice_uidx');
            $table->index(['tenant_id', 'booking_id'], 'booking_operator_fees_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_operator_fees');
        Schema::dropIfExists('operator_fees');
    }
};
