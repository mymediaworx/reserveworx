<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedBigInteger('available_resource_minutes');
            $table->unsignedBigInteger('allocated_resource_minutes');
            $table->unsignedInteger('utilization_bps')->nullable();
            $table->unsignedInteger('bookings_created');
            $table->unsignedInteger('bookings_converted');
            $table->unsignedInteger('conversion_bps')->nullable();
            $table->unsignedBigInteger('average_order_cents')->nullable();
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('revenue_per_resource_hour_cents')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'location_id', 'summary_date'], 'report_summaries_day_uidx');
            $table->index(['tenant_id', 'summary_date'], 'report_summaries_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_summaries');
    }
};
