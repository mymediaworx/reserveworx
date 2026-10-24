<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('local_start_minute')->nullable()->after('ends_on');
            $table->unsignedSmallInteger('local_end_minute')->nullable()->after('local_start_minute');
            $table->json('weekdays')->nullable()->after('local_end_minute');
        });
    }

    public function down(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->dropColumn(['local_start_minute', 'local_end_minute', 'weekdays']);
        });
    }
};
