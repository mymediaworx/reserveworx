<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->unsignedInteger('minutes_late')->nullable()->after('notes');
            $table->foreignUlid('pool_id')->nullable()->after('asset_id')->constrained('pools')->nullOnDelete();
        });

        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE rental_returns MODIFY asset_id CHAR(26) NULL');

        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->string('condition_flag', 32)->nullable()->after('status');
        });

        Schema::table('pools', function (Blueprint $table): void {
            $table->string('condition_flag', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pools', function (Blueprint $table): void {
            $table->dropColumn('condition_flag');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('condition_flag');
        });

        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->dropForeign(['asset_id']);
        });

        DB::statement('ALTER TABLE rental_returns MODIFY asset_id CHAR(26) NOT NULL');

        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->dropConstrainedForeignId('pool_id');
            $table->dropColumn('minutes_late');
        });
    }
};
