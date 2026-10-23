<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('attention_reason', 160)->nullable()->after('guide_user_id');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedInteger('late_fee_per_minute_cents')->nullable()->after('guest_cancel_before_minutes');
        });

        Schema::table('rental_checkouts', function (Blueprint $table) {
            $table->unsignedInteger('late_fee_cents')->nullable()->after('returned_at');
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('pool_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 160);
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id'], 'maint_asset_idx');
            $table->index(['tenant_id', 'pool_id'], 'maint_pool_idx');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary', 500);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'incidents_booking_idx');
        });

        Schema::create('waiver_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('version');
            $table->text('body');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'version'], 'waiver_versions_uidx');
        });

        Schema::create('waiver_signatures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('waiver_version_id')->constrained()->cascadeOnDelete();
            $table->string('participant_name', 120);
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'waiver_sigs_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_signatures');
        Schema::dropIfExists('waiver_versions');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('maintenance_windows');

        Schema::table('rental_checkouts', function (Blueprint $table) {
            $table->dropColumn('late_fee_cents');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('late_fee_per_minute_cents');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('attention_reason');
        });
    }
};
