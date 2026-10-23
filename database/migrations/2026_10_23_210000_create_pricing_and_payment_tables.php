<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 32);
            $table->unsignedInteger('amount_cents');
            $table->string('window', 16)->default('always');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'service_id'], 'price_rules_service_idx');
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'promo_codes_code_uidx');
        });

        Schema::create('pricing_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->json('payload');
            $table->string('rules_version', 64);
            $table->foreignUlid('disclosure_version_id')->nullable()->constrained('disclosure_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique('booking_id', 'pricing_snapshots_booking_uidx');
        });

        Schema::create('stripe_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_account_id', 64);
            $table->string('card_payments_status', 32)->default('pending');
            $table->boolean('livemode')->default(false);
            $table->timestamps();

            $table->unique('tenant_id', 'stripe_accounts_tenant_uidx');
            $table->unique('stripe_account_id', 'stripe_accounts_acct_uidx');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('fee_cents')->default(0);
            $table->string('stripe_checkout_session_id', 80)->nullable();
            $table->string('stripe_payment_intent_id', 80)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'payments_booking_idx');
            $table->unique('stripe_checkout_session_id', 'payments_session_uidx');
        });

        Schema::create('platform_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('stripe_fee_id', 80)->nullable();
            $table->string('channel', 32);
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'platform_fees_booking_idx');
            $table->unique('stripe_fee_id', 'platform_fees_stripe_uidx');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('stripe_event_id', 80);
            $table->string('type', 80);
            $table->string('stripe_account_id', 64)->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('stripe_event_id', 'webhook_events_stripe_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('platform_fees');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('stripe_accounts');
        Schema::dropIfExists('pricing_snapshots');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('price_rules');
    }
};
