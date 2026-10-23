<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('min_participants')->nullable()->after('kind');
            $table->unsignedInteger('max_participants')->nullable()->after('min_participants');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUlid('created_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'created_by'], 'bookings_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_created_by_idx');
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['min_participants', 'max_participants']);
        });
    }
};
