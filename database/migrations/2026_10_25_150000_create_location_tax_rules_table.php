<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_tax_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('percent_bps');
            $table->boolean('applies_to_rentals')->default(false);
            $table->boolean('applies_to_add_ons')->default(false);
            $table->boolean('applies_to_fees')->default(false);
            $table->timestamps();

            $table->unique(['location_id', 'name'], 'location_tax_rules_name_uidx');
            $table->index(['tenant_id', 'location_id'], 'location_tax_rules_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_tax_rules');
    }
};
