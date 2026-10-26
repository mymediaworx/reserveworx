<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_mutations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('idempotency_key', 80);
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'booking_mutations_idempotency_uidx');
            $table->index(['tenant_id', 'booking_id'], 'booking_mutations_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_mutations');
    }
};
