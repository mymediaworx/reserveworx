<?php

use App\Services\Search\StaffSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_search_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->ulid('subject_id');
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 80);
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'token'], 'staff_search_token_idx');
            $table->index(['tenant_id', 'subject_type', 'location_id', 'token'], 'staff_search_location_token_idx');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['tenant_id', 'customer_id'], 'bookings_tenant_customer_idx');
        });

        app(StaffSearchIndex::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_tenant_customer_idx');
        });

        Schema::dropIfExists('staff_search_tokens');
    }
};
