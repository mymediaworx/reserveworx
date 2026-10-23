<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('waitlist_enabled')->default(false)->after('max_participants');
        });

        Schema::create('departures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('private')->default(false);
            $table->unsignedInteger('seats')->nullable();
            $table->string('status', 32);
            $table->timestamps();

            $table->index(['tenant_id', 'service_id', 'starts_at'], 'departures_service_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUlid('departure_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
            $table->index(['tenant_id', 'departure_id'], 'bookings_departure_idx');
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('departure_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('participants');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('status', 32);
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'waitlist_idempotency_uidx');
            $table->index(['tenant_id', 'service_id', 'starts_at'], 'waitlist_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_departure_idx');
            $table->dropConstrainedForeignId('departure_id');
        });

        Schema::dropIfExists('departures');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('waitlist_enabled');
        });
    }
};
