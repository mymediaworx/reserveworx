<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignUlid('replacement_asset_id')
                ->nullable()
                ->after('resource_category_id')
                ->constrained('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_asset_id');
        });
    }
};
