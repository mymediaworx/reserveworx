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
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->json('reminder_offsets_minutes')->nullable()->after('pay_at_pickup');
        });

        Schema::create('booking_reminders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'offset_minutes'], 'booking_reminders_offset_uidx');
            $table->index(['tenant_id', 'booking_id'], 'booking_reminders_tenant_idx');
        });

        $permission = Permission::query()->updateOrCreate(
            ['slug' => 'reminders.manage'],
            ['name' => 'Read and update booking reminder offsets'],
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
        $permission = Permission::query()->where('slug', 'reminders.manage')->first();

        if ($permission instanceof Permission) {
            Role::query()
                ->whereIn('slug', ['owner', 'manager'])
                ->orderBy('id')
                ->each(function (Role $role) use ($permission): void {
                    $role->permissions()->detach($permission->id);
                });
            $permission->delete();
        }

        Schema::dropIfExists('booking_reminders');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('reminder_offsets_minutes');
        });
    }
};
