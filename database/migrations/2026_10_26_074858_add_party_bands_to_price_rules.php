<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_participants')->nullable()->after('weekdays');
            $table->unsignedSmallInteger('max_participants')->nullable()->after('min_participants');
        });
    }

    public function down(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->dropColumn(['min_participants', 'max_participants']);
        });
    }
};
