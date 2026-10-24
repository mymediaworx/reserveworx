<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New default look: BrandWorx green with an amber accent. Workspaces still on
 * both old defaults never picked colors, so they move to the new ones.
 * Anyone who chose colors keeps them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_brandings', function (Blueprint $table): void {
            $table->char('primary_color', 7)->default('#0F5C4D')->change();
            $table->char('accent_color', 7)->default('#F59E0B')->change();
        });

        DB::table('tenant_brandings')
            ->where('primary_color', '#0E7C86')
            ->where('accent_color', '#F26B3A')
            ->update(['primary_color' => '#0F5C4D', 'accent_color' => '#F59E0B']);
    }

    public function down(): void
    {
        Schema::table('tenant_brandings', function (Blueprint $table): void {
            $table->char('primary_color', 7)->default('#0E7C86')->change();
            $table->char('accent_color', 7)->default('#F26B3A')->change();
        });

        DB::table('tenant_brandings')
            ->where('primary_color', '#0F5C4D')
            ->where('accent_color', '#F59E0B')
            ->update(['primary_color' => '#0E7C86', 'accent_color' => '#F26B3A']);
    }
};
