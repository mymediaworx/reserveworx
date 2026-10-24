<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable()->after('phone');
        });

        Schema::create('customer_data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->json('row_counts')->nullable();
            $table->string('status', 32);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status'], 'customer_exports_customer_idx');
        });

        Schema::create('customer_data_deletions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('export_id')->nullable()->constrained('customer_data_exports')->nullOnDelete();
            $table->boolean('confirmed')->default(false);
            $table->timestamp('anonymized_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id'], 'customer_deletions_customer_uidx');
        });

        $permission = Permission::query()->updateOrCreate(
            ['slug' => 'customers.privacy'],
            ['name' => 'Export and anonymize one customer'],
        );

        Role::query()
            ->whereIn('slug', ['owner', 'manager'])
            ->orderBy('id')
            ->each(function (Role $role) use ($permission): void {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            });
    }

    public function down(): void
    {
        $permission = Permission::query()->where('slug', 'customers.privacy')->first();

        if ($permission instanceof Permission) {
            Role::query()
                ->whereIn('slug', ['owner', 'manager'])
                ->orderBy('id')
                ->each(function (Role $role) use ($permission): void {
                    $role->permissions()->detach($permission->id);
                });
            $permission->delete();
        }

        Schema::dropIfExists('customer_data_deletions');
        Schema::dropIfExists('customer_data_exports');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('anonymized_at');
        });
    }
};
