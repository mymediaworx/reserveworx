<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'participants_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
