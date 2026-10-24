<?php

use App\Enums\TransactionalEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('logo_path')->nullable();
            $table->string('logo_mime', 64)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->char('primary_color', 7)->default('#0E7C86');
            $table->char('accent_color', 7)->default('#F26B3A');
            $table->string('support_email')->nullable();
            $table->string('support_phone', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_notification_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reply_to')->nullable();
            $table->json('staff_alert_recipients');
            $table->json('transactional_emails');
            $table->timestamps();
        });

        $now = Carbon::now();
        $emails = json_encode(TransactionalEmail::defaults(), JSON_THROW_ON_ERROR);

        foreach (DB::table('tenants')->select('id', 'name')->orderBy('id')->get() as $tenant) {
            $name = is_string($tenant->name) ? trim($tenant->name) : '';

            DB::table('tenant_brandings')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'display_name' => $name === '' ? 'Workspace' : mb_substr($name, 0, 120),
                'primary_color' => '#0E7C86',
                'accent_color' => '#F26B3A',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('tenant_notification_settings')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'staff_alert_recipients' => '[]',
                'transactional_emails' => $emails,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notification_settings');
        Schema::dropIfExists('tenant_brandings');
    }
};
