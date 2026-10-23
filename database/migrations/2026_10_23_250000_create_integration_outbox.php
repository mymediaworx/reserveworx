<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_hooks', function (Blueprint $table) {
            $table->string('delivery_status', 32)->default('queued')->after('payload');
            $table->string('delivery_error', 255)->nullable()->after('delivery_status');
            $table->timestamp('attempted_at')->nullable()->after('delivery_error');
        });

        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('destination', 32);
            $table->string('kind', 64);
            $table->string('dedupe_key', 160)->nullable();
            $table->json('payload');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'outbox_dedupe_uidx');
            $table->index(['tenant_id', 'status', 'available_at'], 'outbox_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');

        Schema::table('notification_hooks', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivery_error', 'attempted_at']);
        });
    }
};
