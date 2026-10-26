<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreignUlid('pool_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'pool_id'], 'incidents_pool_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropIndex('incidents_pool_idx');
            $table->dropConstrainedForeignId('pool_id');
        });
    }
};
