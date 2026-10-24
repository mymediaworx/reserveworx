<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        Schema::table('memberships', function (Blueprint $table): void {
            $table->char('invite_token_hash', 64)->nullable()->after('status');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token_hash');
        });

        DB::table('users')->whereNull('password_set_at')->update([
            'password_set_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn(['invite_token_hash', 'invite_expires_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_set_at');
        });
    }
};
