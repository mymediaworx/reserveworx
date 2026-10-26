<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('kind', 64);
            $table->unsignedInteger('attempt');
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'integration_logs_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
