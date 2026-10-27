<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_mutations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('idempotency_key', 80);
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'waitlist_mutations_idempotency_uidx');
            $table->index(['tenant_id', 'waitlist_entry_id'], 'waitlist_mutations_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_mutations');
    }
};
