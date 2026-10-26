<?php

namespace Tests\Feature\Platform;

use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Tenancy\TenantScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Notification::fake();
        config(['reserveworx.booking_apex' => 'reserveworx.com']);
    }

    public function test_it_creates_the_workspace_location_owner_and_domain_once(): void
    {
        $args = ['slug' => 'yakshack', '--name' => 'Yak Shack', '--owner-email' => 'Owner@Example.com', '--timezone' => 'America/New_York'];

        $this->artisan('reserveworx:tenant:create', $args)->assertSuccessful();
        $this->artisan('reserveworx:tenant:create', $args)->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'yakshack')->sole();
        $this->assertSame('Yak Shack', $tenant->name);
        $location = Location::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('America/New_York', $location->timezone);
        $domain = Domain::withoutGlobalScope(TenantScope::class)->where('hostname', 'yakshack.reserveworx.com')->sole();
        $this->assertSame($tenant->id, $domain->tenant_id);
        $this->assertNotNull($domain->verified_at);

        $owner = User::query()->where('email', 'owner@example.com')->sole();
        $this->assertSame(1, Membership::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());
        Notification::assertSentToTimes($owner, ResetPasswordLink::class, 1);

        // Fee defaults are untouched: exactly one settings row, created by the provisioner.
        $this->assertSame(1, TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());
    }

    public function test_it_rejects_reserved_or_malformed_slugs(): void
    {
        $this->artisan('reserveworx:tenant:create', ['slug' => 'app', '--name' => 'X'])->assertFailed();
        $this->artisan('reserveworx:tenant:create', ['slug' => 'Bad_Slug!', '--name' => 'X'])->assertFailed();
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_sync_applies_the_manifest_and_prints_hosts(): void
    {
        config(['tenants' => [
            ['slug' => 'yakshack', 'name' => 'Yak Shack', 'owner_email' => 'michael@brandworx.ai', 'timezone' => 'America/New_York'],
            ['slug' => 'noowner', 'name' => 'No Owner', 'owner_email' => '', 'timezone' => 'America/New_York'],
        ]]);

        $this->artisan('reserveworx:tenant:sync')
            ->expectsOutputToContain('tenant_host=yakshack.reserveworx.com')
            ->expectsOutputToContain('owner=missing')
            ->assertSuccessful();
        $this->artisan('reserveworx:tenant:sync')->expectsOutputToContain('created=none')->assertSuccessful();

        $this->assertSame(2, Tenant::query()->count());
    }

    public function test_the_committed_manifest_lists_yak_shack(): void
    {
        $manifest = require base_path('config/tenants.php');
        $this->assertContains('yakshack', array_column($manifest, 'slug'));
    }
}
