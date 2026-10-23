<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedInteger('bytes');
            $table->json('row_counts');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'completed_at'], 'tenant_exports_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_data_exports');
    }
};
