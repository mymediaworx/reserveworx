<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->ulid('owner_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_local');
            $table->time('closes_local');
            $table->timestamps();

            $table->index(['tenant_id', 'owner_type', 'owner_id', 'weekday'], 'schedules_owner_weekday_idx');
        });

        Schema::create('blackouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 32);
            $table->timestamps();

            $table->index(['tenant_id', 'location_id', 'kind']);
        });

        Schema::create('resource_requirements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 32);
            $table->ulid('resource_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('per_participants')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'service_id']);
            $table->index(['tenant_id', 'resource_type', 'resource_id'], 'requirements_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_requirements');
        Schema::dropIfExists('services');
        Schema::dropIfExists('blackouts');
        Schema::dropIfExists('availability_schedules');
    }
};
