<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->unsignedInteger('late_grace_minutes')->nullable()->after('late_fee_per_minute_cents');
        });

        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->unsignedInteger('late_fee_cents')->nullable()->after('minutes_late');
            $table->string('late_fee_decision', 16)->nullable()->after('late_fee_cents');
            $table->string('late_fee_reason', 500)->nullable()->after('late_fee_decision');
        });
    }

    public function down(): void
    {
        Schema::table('rental_returns', function (Blueprint $table): void {
            $table->dropColumn(['late_fee_cents', 'late_fee_decision', 'late_fee_reason']);
        });

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('late_grace_minutes');
        });
    }
};
