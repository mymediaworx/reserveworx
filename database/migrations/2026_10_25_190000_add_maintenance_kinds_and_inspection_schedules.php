<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->string('kind', 20)->default('maintenance')->after('pool_id');
        });

        Schema::create('inspection_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->unsignedSmallInteger('interval_uses')->nullable();
            $table->timestamp('last_done_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id'], 'inspection_asset_idx');
            $table->index(['tenant_id', 'next_due_at'], 'inspection_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_schedules');

        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
