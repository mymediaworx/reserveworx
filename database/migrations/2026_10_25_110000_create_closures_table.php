<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('resource_category_id')->nullable()->constrained('resource_categories')->nullOnDelete();
            $table->string('resource_type', 16)->nullable();
            $table->ulid('resource_id')->nullable();
            $table->string('reason', 200);
            $table->boolean('all_day');
            $table->boolean('recurs_yearly');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('starts_local')->nullable();
            $table->time('ends_local')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'location_id', 'starts_on'], 'closures_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closures');
    }
};
