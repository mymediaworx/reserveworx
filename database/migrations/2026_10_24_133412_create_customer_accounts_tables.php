<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 120)->nullable();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email'], 'customer_accounts_tenant_email_uidx');
        });

        Schema::create('customer_sign_in_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_account_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'customer_sign_in_tokens_hash_uidx');
            $table->index(['tenant_id', 'customer_account_id'], 'customer_sign_in_tokens_account_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUlid('customer_account_id')
                ->nullable()
                ->after('phone')
                ->constrained('customer_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });

        Schema::dropIfExists('customer_sign_in_tokens');
        Schema::dropIfExists('customer_accounts');
    }
};
