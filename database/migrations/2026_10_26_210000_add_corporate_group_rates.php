<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::table('price_rules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('percent_off')->nullable()->after('max_participants');
            $table->foreignUlid('corporate_account_id')
                ->nullable()
                ->after('percent_off')
                ->constrained('corporate_accounts')
                ->restrictOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignUlid('corporate_account_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('corporate_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
        });

        Schema::table('price_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropColumn('percent_off');
        });

        Schema::dropIfExists('corporate_accounts');
    }
};
