<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table): void {
            $table->timestamp('ended_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table): void {
            $table->dropColumn('ended_at');
        });
    }
};
