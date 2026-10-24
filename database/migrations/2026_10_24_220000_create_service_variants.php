<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('price_override_cents')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'service_id', 'name'], 'service_variants_name_uidx');
            $table->index(['tenant_id', 'service_id'], 'service_variants_service_idx');
        });

        Schema::create('service_variant_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_variant_id')->constrained('service_variants')->cascadeOnDelete();
            $table->foreignUlid('resource_category_id')->constrained()->restrictOnDelete();
            $table->string('resource_type', 32);
            $table->ulid('resource_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();

            $table->unique(['service_variant_id', 'resource_type', 'resource_id'], 'variant_resources_uidx');
            $table->index(['tenant_id', 'service_variant_id'], 'variant_resources_variant_idx');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignUlid('service_variant_id')
                ->nullable()
                ->after('service_id')
                ->constrained('service_variants')
                ->restrictOnDelete();
        });

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->foreignUlid('service_variant_id')
                ->nullable()
                ->after('service_id')
                ->constrained('service_variants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_variant_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_variant_id');
        });

        Schema::dropIfExists('service_variant_resources');
        Schema::dropIfExists('service_variants');
    }
};
