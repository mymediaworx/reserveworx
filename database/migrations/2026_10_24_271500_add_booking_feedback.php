<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('feedback_opted_out_at')->nullable()->after('anonymized_at');
        });

        Schema::table('tenant_notification_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('feedback_delay_minutes')->default(120)->after('transactional_emails');
            $table->string('review_url', 2048)->nullable()->after('feedback_delay_minutes');
        });

        Schema::create('feedback_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('send_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('skipped_at')->nullable();
            $table->string('skip_reason', 255)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('rated_at')->nullable();
            $table->timestamps();

            $table->unique('booking_id', 'feedback_requests_booking_uidx');
            $table->index(['tenant_id', 'send_at'], 'feedback_requests_due_idx');
            $table->index(['tenant_id', 'rated_at'], 'feedback_requests_rated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_requests');

        Schema::table('tenant_notification_settings', function (Blueprint $table): void {
            $table->dropColumn(['feedback_delay_minutes', 'review_url']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('feedback_opted_out_at');
        });
    }
};
