<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedInteger('lead_minutes');
            $table->unsignedTinyInteger('refund_percent');
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'lead_minutes'], 'cancellation_policies_tier_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
