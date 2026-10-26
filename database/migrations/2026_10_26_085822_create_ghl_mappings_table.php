<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghl_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('location_id', 64);
            $table->string('contact_id', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id', 'location_id'], 'ghl_mappings_customer_location_uniq');
            $table->index(['tenant_id', 'location_id'], 'ghl_mappings_tenant_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghl_mappings');
    }
};
