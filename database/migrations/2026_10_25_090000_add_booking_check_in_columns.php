<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('operational_status');
            $table->foreignUlid('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $table->string('check_in_waiver_override_reason', 500)->nullable()->after('checked_in_by');
            $table->index(['tenant_id', 'location_id', 'starts_at'], 'bookings_location_start_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_location_start_idx');
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn(['checked_in_at', 'check_in_waiver_override_reason']);
        });
    }
};
