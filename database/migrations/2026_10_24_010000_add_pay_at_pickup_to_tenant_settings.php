<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->boolean('pay_at_pickup')->default(false)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('pay_at_pickup');
        });
    }
};
