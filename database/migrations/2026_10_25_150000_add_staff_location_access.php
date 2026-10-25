<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->boolean('locations_restricted')->default(false)->after('status');
        });

        Schema::create('membership_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'location_id']);
            $table->index(['tenant_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_locations');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn('locations_restricted');
        });
    }
};
