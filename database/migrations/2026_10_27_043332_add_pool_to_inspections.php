<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_schedules', function (Blueprint $table): void {
            $table->foreignUlid('pool_id')->nullable()->after('asset_id')->constrained('pools')->cascadeOnDelete();
            $table->index(['tenant_id', 'pool_id'], 'inspection_pool_idx');
        });

        Schema::table('inspection_schedules', function (Blueprint $table): void {
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE inspection_schedules MODIFY asset_id CHAR(26) NULL');

        Schema::table('inspection_schedules', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE inspection_schedules ADD CONSTRAINT inspection_one_resource CHECK ((asset_id IS NOT NULL AND pool_id IS NULL) OR (asset_id IS NULL AND pool_id IS NOT NULL))');

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreignUlid('pool_id')->nullable()->after('asset_id')->constrained('pools')->cascadeOnDelete();
            $table->index(['tenant_id', 'pool_id', 'inspected_at'], 'maint_records_pool_idx');
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE maintenance_records MODIFY asset_id CHAR(26) NULL');

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE maintenance_records ADD CONSTRAINT maint_record_one_resource CHECK ((asset_id IS NOT NULL AND pool_id IS NULL) OR (asset_id IS NULL AND pool_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inspection_schedules DROP CONSTRAINT inspection_one_resource');
        DB::statement('ALTER TABLE maintenance_records DROP CONSTRAINT maint_record_one_resource');

        DB::table('inspection_schedules')->whereNull('asset_id')->delete();
        DB::table('maintenance_records')->whereNull('asset_id')->delete();

        Schema::table('inspection_schedules', function (Blueprint $table): void {
            $table->dropIndex('inspection_pool_idx');
            $table->dropConstrainedForeignId('pool_id');
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE inspection_schedules MODIFY asset_id CHAR(26) NOT NULL');

        Schema::table('inspection_schedules', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropIndex('maint_records_pool_idx');
            $table->dropConstrainedForeignId('pool_id');
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE maintenance_records MODIFY asset_id CHAR(26) NOT NULL');

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });
    }
};
