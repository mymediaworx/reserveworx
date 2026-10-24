<?php

namespace Tests\Feature\Platform;

use App\Models\Domain;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachVerifiedDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_harbor_demo_gains_a_verified_hostname_without_taking_the_primary(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Harbor Demo',
            'slug' => 'harbor-demo',
        ]);
        Domain::factory()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'harbor-demo.reserveworx.test',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->artisan('reserveworx:attach-verified-domain', [
            'slug' => 'harbor-demo',
            'hostname' => 'Harbor.ReserveWorx.com',
        ])->assertSuccessful();

        $attached = Domain::withoutGlobalScope(TenantScope::class)
            ->where('hostname', 'harbor.reserveworx.com')
            ->firstOrFail();
        $this->assertSame($tenant->id, $attached->tenant_id);
        $this->assertNotNull($attached->verified_at);
        $this->assertFalse($attached->is_primary);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'domain.verified',
            'tenant_id' => $tenant->id,
            'subject_id' => $attached->id,
        ]);

        $this->artisan('reserveworx:attach-verified-domain', [
            'slug' => 'harbor-demo',
            'hostname' => 'harbor.reserveworx.com',
        ])->assertSuccessful();

        $this->assertSame(1, Domain::withoutGlobalScope(TenantScope::class)->where('hostname', 'harbor.reserveworx.com')->count());
    }

    public function test_a_hostname_is_not_moved_off_another_workspace_or_onto_the_platform(): void
    {
        $harbor = Tenant::factory()->create(['slug' => 'harbor-demo']);
        $other = Tenant::factory()->create(['slug' => 'inland-demo']);
        Domain::factory()->create([
            'tenant_id' => $other->id,
            'hostname' => 'harbor.reserveworx.com',
            'verified_at' => now(),
        ]);

        $this->artisan('reserveworx:attach-verified-domain', [
            'slug' => 'harbor-demo',
            'hostname' => 'harbor.reserveworx.com',
        ])->assertFailed();

        $this->assertSame(
            $other->id,
            Domain::withoutGlobalScope(TenantScope::class)->where('hostname', 'harbor.reserveworx.com')->value('tenant_id'),
        );
        $this->assertNotSame($harbor->id, $other->id);

        $this->artisan('reserveworx:attach-verified-domain', [
            'slug' => 'harbor-demo',
            'hostname' => 'app.reserveworx.com',
        ])->assertFailed();

        $this->artisan('reserveworx:attach-verified-domain', [
            'slug' => 'missing-demo',
            'hostname' => 'bookings.example.com',
        ])->assertFailed();
    }
}
