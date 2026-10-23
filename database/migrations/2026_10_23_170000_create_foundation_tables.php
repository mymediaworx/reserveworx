<?php

use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->string('status', 32)->default('onboarding')->index();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('hold_minutes')->default(15);
            $table->unsignedSmallInteger('ledger_bucket_minutes')->default(15);
            $table->string('fee_payer', 32)->default('customer_paid');
            $table->unsignedSmallInteger('direct_fee_bps')->default(300);
            $table->unsignedSmallInteger('third_party_fee_bps')->default(100);
            $table->unsignedInteger('direct_fee_fixed_cents')->default(0);
            $table->unsignedInteger('third_party_fee_fixed_cents')->default(0);
            $table->unsignedInteger('direct_fee_minimum_cents')->nullable();
            $table->unsignedInteger('third_party_fee_minimum_cents')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('type', 32);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('platform_administrators', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->json('properties');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        (new RoleAndPermissionSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('platform_administrators');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('tenants');
    }
};
