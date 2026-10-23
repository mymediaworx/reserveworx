<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_guide')->default(false)->after('capacity');
            $table->unsignedSmallInteger('turnaround_minutes')->default(0)->after('return_buffer_minutes');
        });

        Schema::table('pools', function (Blueprint $table) {
            $table->unsignedSmallInteger('turnaround_minutes')->default(0)->after('return_buffer_minutes');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUlid('guide_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'guide_user_id'], 'bookings_guide_idx');
        });

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->foreignUlid('booking_id')->nullable()->after('departure_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'booking_id'], 'waitlist_booking_idx');
        });

        Schema::create('service_durations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('quantity');
            $table->string('unit', 16);
            $table->unsignedInteger('duration_minutes');
            $table->timestamps();

            $table->unique(['service_id', 'name'], 'durations_name_uidx');
            $table->index(['tenant_id', 'service_id'], 'durations_service_idx');
        });

        Schema::create('rental_checkouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('pool_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('checked_out_at');
            $table->dateTime('due_at');
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'checkouts_booking_idx');
            $table->index(['asset_id', 'returned_at'], 'checkouts_asset_open_idx');
            $table->index(['pool_id', 'returned_at'], 'checkouts_pool_open_idx');
        });

        Schema::create('notification_hooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 64);
            $table->json('payload');
            $table->timestamps();

            $table->index(['tenant_id', 'kind'], 'hooks_tenant_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_hooks');
        Schema::dropIfExists('rental_checkouts');
        Schema::dropIfExists('service_durations');

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('waitlist_booking_idx');
            $table->dropConstrainedForeignId('booking_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_guide_idx');
            $table->dropConstrainedForeignId('guide_user_id');
        });

        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn('turnaround_minutes');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['is_guide', 'turnaround_minutes']);
        });
    }
};
