<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_list_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_list_views');
    }
};
