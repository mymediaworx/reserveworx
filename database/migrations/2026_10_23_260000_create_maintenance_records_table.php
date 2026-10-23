<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamp('inspected_at');
            $table->string('summary', 500);
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id', 'inspected_at'], 'maint_records_asset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
