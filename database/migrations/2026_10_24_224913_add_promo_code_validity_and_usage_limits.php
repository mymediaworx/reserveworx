<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->after('amount_off_cents');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->unsignedInteger('usage_limit')->nullable()->after('ends_on');
            $table->unsignedInteger('redeemed_count')->default(0)->after('usage_limit');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->dropColumn(['starts_on', 'ends_on', 'usage_limit', 'redeemed_count']);
        });
    }
};
