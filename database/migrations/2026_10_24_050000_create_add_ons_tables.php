<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('kind', 32);
            $table->string('pricing', 32);
            $table->unsignedInteger('amount_cents');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('max_quantity')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'add_ons_name_uidx');
            $table->index(['tenant_id', 'active'], 'add_ons_active_idx');
        });

        Schema::create('booking_add_ons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('add_on_id')->constrained('add_ons')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('kind', 32);
            $table->string('pricing', 32);
            $table->unsignedInteger('unit_amount_cents');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('total_cents');
            $table->timestamps();

            $table->unique(['booking_id', 'add_on_id'], 'booking_add_ons_choice_uidx');
            $table->index(['tenant_id', 'booking_id'], 'booking_add_ons_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_add_ons');
        Schema::dropIfExists('add_ons');
    }
};
