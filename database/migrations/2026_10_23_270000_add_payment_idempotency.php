<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable()->after('booking_id');
            $table->char('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['tenant_id', 'idempotency_key'], 'payments_idempotency_uidx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_idempotency_uidx');
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
