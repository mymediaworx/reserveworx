<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedInteger('guest_cancel_before_minutes')->nullable()->after('hold_minutes');
        });

        Schema::table('notification_hooks', function (Blueprint $table) {
            $table->string('dedupe_key', 80)->nullable()->after('kind');
            $table->unique('dedupe_key', 'hooks_dedupe_uidx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_hooks', function (Blueprint $table) {
            $table->dropUnique('hooks_dedupe_uidx');
            $table->dropColumn('dedupe_key');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('guest_cancel_before_minutes');
        });
    }
};
