<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone', 64);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('resource_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('resource_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('prep_buffer_minutes')->default(0);
            $table->unsignedSmallInteger('return_buffer_minutes')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'location_id', 'status']);
        });

        Schema::create('pools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('resource_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('prep_buffer_minutes')->default(0);
            $table->unsignedSmallInteger('return_buffer_minutes')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pools');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('resource_categories');
        Schema::dropIfExists('locations');
    }
};
