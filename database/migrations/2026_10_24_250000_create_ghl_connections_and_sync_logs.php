<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghl_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('location_id', 64);
            $table->text('private_integration_token');
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('ghl_sync_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 64);
            $table->unsignedInteger('attempt');
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 255)->nullable();
            $table->string('location_id', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'ghl_sync_logs_tenant_created_idx');
        });

        Permission::query()->updateOrCreate(
            ['slug' => 'integrations.view'],
            ['name' => 'View the GoHighLevel sub-account link'],
        );
        Permission::query()->updateOrCreate(
            ['slug' => 'integrations.manage'],
            ['name' => 'Link a workspace to a GoHighLevel sub-account'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ghl_sync_logs');
        Schema::dropIfExists('ghl_connections');

        $ids = Permission::query()
            ->whereIn('slug', ['integrations.view', 'integrations.manage'])
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            Permission::query()->whereIn('id', $ids)->delete();
        }
    }
};
