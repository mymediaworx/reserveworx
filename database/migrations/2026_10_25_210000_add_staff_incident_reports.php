<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('severity', 16)->nullable()->after('asset_id');
            $table->text('notes')->nullable()->after('summary');
            $table->unsignedInteger('damage_cents')->nullable()->after('notes');
            $table->string('status', 16)->default('open')->after('damage_cents');
            $table->foreignUlid('reported_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('reported_by');
            $table->foreignUlid('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->foreignUlid('maintenance_window_id')->nullable()->after('resolved_by')->constrained()->nullOnDelete();

            $table->index(['tenant_id', 'status', 'occurred_at'], 'incidents_status_idx');
        });

        Schema::create('incident_photos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('incident_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->timestamps();

            $table->index(['tenant_id', 'incident_id'], 'incident_photos_incident_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_photos');

        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropIndex('incidents_status_idx');
            $table->dropConstrainedForeignId('maintenance_window_id');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('reported_by');
            $table->dropColumn(['severity', 'notes', 'damage_cents', 'status', 'resolved_at']);
        });
    }
};
