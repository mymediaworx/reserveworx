<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 120)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'participants_idempotency_uidx');
            $table->index(['tenant_id', 'booking_id'], 'participants_booking_idx');
        });

        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->foreignUlid('participant_id')->nullable()->after('booking_id')->constrained('participants')->nullOnDelete();
            $table->unique(['tenant_id', 'participant_id', 'waiver_version_id'], 'waiver_sigs_participant_uidx');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->dropUnique('waiver_sigs_participant_uidx');
            $table->dropConstrainedForeignId('participant_id');
        });

        Schema::dropIfExists('participants');
    }
};
