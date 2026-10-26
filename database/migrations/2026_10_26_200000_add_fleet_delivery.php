<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('location_fleet_reserves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('fleet_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'fleet_item_id']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('delivered_from_fleet')->default(false)->after('booking_fee_taxable');
            $table->boolean('walk_ins_enabled')->default(false)->after('delivered_from_fleet');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('counter_sale')->default(false)->after('participants');
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignUlid('fleet_item_id')->nullable()->after('pool_id')->constrained('fleet_items')->nullOnDelete();
            $table->index(['tenant_id', 'fleet_item_id', 'starts_at', 'ends_at'], 'alloc_fleet_interval_idx');
        });

        Schema::table('holds', function (Blueprint $table) {
            $table->foreignUlid('fleet_item_id')->nullable()->after('pool_id')->constrained('fleet_items')->nullOnDelete();
            $table->index(['tenant_id', 'fleet_item_id', 'expires_at'], 'holds_fleet_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table) {
            $table->dropIndex('holds_fleet_expiry_idx');
            $table->dropConstrainedForeignId('fleet_item_id');
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->dropIndex('alloc_fleet_interval_idx');
            $table->dropConstrainedForeignId('fleet_item_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('counter_sale');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['delivered_from_fleet', 'walk_ins_enabled']);
        });

        Schema::dropIfExists('location_fleet_reserves');
        Schema::dropIfExists('fleet_items');
    }
};
