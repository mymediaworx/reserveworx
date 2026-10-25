<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('pools', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('closures', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::create('staff_undo_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->json('payload');
            $table->string('message', 200);
            $table->timestamp('expires_at');
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'committed_at'], 'staff_undo_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_undo_actions');

        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('closures', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('pools', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
