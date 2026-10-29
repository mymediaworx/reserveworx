<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\ActivityLog;
use App\Livewire\Staff\Menu;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAuditLogTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_sees_the_log_in_the_location_timezone_without_secrets(): void
    {
        $workspace = $this->signedIn();
        $workspace['user']->name = 'Casey Owner';
        $workspace['user']->save();
        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor dock',
            'timezone' => 'America/New_York',
        ]);
        $this->row($workspace['tenant']->id, [
            'actor_user_id' => $workspace['user']->id,
            'action' => 'booking.cancelled',
            'properties' => [
                'reason' => 'Window ended early',
                'private_integration_token' => 'pit-secret-value',
                'note' => ['token' => 'nested-secret', 'text' => 'Kept the boat'],
            ],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'SecretBrowser/1.0',
            'created_at' => CarbonImmutable::parse('2026-10-27 15:04:00', 'UTC'),
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'maintenance.ended',
            'properties' => [],
            'created_at' => CarbonImmutable::parse('2026-10-27 14:00:00', 'UTC'),
        ]);
        $before = AuditLog::query()->count();

        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Audit log')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('Booking cancelled')
            ->assertSee('Tue, Oct 27, 11:04 AM EDT')
            ->assertSee('Casey Owner')
            ->assertSee('reason: Window ended early')
            ->assertSee('text: Kept the boat')
            ->assertSee('From 203.0.113.10')
            ->assertSee('Maintenance ended')
            ->assertSee('ReserveWorx')
            ->assertSee('Tue, Oct 27, 10:00 AM EDT')
            ->assertDontSee('pit-secret-value')
            ->assertDontSee('nested-secret')
            ->assertDontSee('SecretBrowser')
            ->assertDontSee('private_integration_token');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Audit log')
            ->assertSee('/staff/audit', false);

        $this->keep($workspace);
        Livewire::test(Menu::class)->assertSee('Audit log');
    }

    public function test_a_filter_keeps_an_exact_action_and_only_the_latest_fifty(): void
    {
        $workspace = $this->signedIn();
        $this->row($workspace['tenant']->id, [
            'action' => 'booking.cancelled',
            'properties' => ['reason' => 'Shown when filtered'],
            'created_at' => CarbonImmutable::parse('2026-10-27 12:00:00', 'UTC'),
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'asset.updated',
            'properties' => ['reason' => 'Hidden by the filter'],
            'created_at' => CarbonImmutable::parse('2026-10-27 13:00:00', 'UTC'),
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'sample.dropped',
            'properties' => ['reason' => 'Too old to list'],
            'created_at' => CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'),
        ]);

        for ($index = 1; $index <= 50; $index++) {
            $this->row($workspace['tenant']->id, [
                'action' => 'sample.kept.'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'properties' => [],
                'created_at' => CarbonImmutable::parse('2026-10-27 16:00:00', 'UTC')->addMinutes($index),
            ]);
        }

        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Showing the latest 50.')
            ->assertSee('Sample kept 50')
            ->assertDontSee('Too old to list')
            ->assertDontSee('Sample dropped');

        $this->get($this->tenantUrl('alpha.test', '/staff/audit?action=booking.cancelled'))
            ->assertOk()
            ->assertSee('Shown when filtered')
            ->assertSee('Booking cancelled')
            ->assertDontSee('Hidden by the filter')
            ->assertDontSee('Sample kept 50');

        $this->keep($workspace);
        Livewire::test(ActivityLog::class)
            ->set('action', str_repeat('a', 256))
            ->assertSee('Use 255 characters or fewer.')
            ->assertSee('Nothing is listed until the action is 255 characters or fewer.')
            ->assertDontSee('Booking cancelled');
    }

    public function test_a_booking_subject_links_to_that_booking(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        app(TenantManager::class)->set($workspace['tenant']);
        $start = CarbonImmutable::parse('2026-11-03 15:00:00', 'UTC');
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $shop['service']->id,
            $start,
            $start->addHour(),
            2,
            null,
            'Ada Lovelace',
            'ada@example.com',
        ));
        $this->row($workspace['tenant']->id, [
            'action' => 'booking.held',
            'subject_type' => $booking::class,
            'subject_id' => $booking->id,
            'properties' => [],
            'created_at' => CarbonImmutable::parse('2026-10-27 16:00:00', 'UTC'),
        ]);

        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('href="/staff/bookings/'.$booking->id.'"', false)
            ->assertSee('Open booking');
    }

    public function test_staff_are_refused_and_the_link_is_hidden(): void
    {
        $staff = $this->signedIn('staff');
        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))->assertForbidden();
        $this->keep($staff);
        Livewire::test(ActivityLog::class)->assertForbidden();
        $this->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertDontSee('/staff/audit', false);
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('/staff/audit', false);
    }

    public function test_a_location_limited_owner_is_refused_and_the_link_is_hidden(): void
    {
        $owner = $this->signedIn();
        $home = Location::factory()->create([
            'tenant_id' => $owner['tenant']->id,
            'name' => 'Home dock',
        ]);
        $owner['membership']->locations_restricted = true;
        $owner['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $owner['tenant']->id,
            'membership_id' => $owner['membership']->id,
            'location_id' => $home->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))->assertForbidden();
        $this->keep($owner);
        Livewire::test(ActivityLog::class)->assertForbidden();
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('/staff/audit', false);
    }

    public function test_a_manager_can_open_the_page_and_another_workspace_is_hidden(): void
    {
        $manager = $this->signedIn('manager');
        $this->row($manager['tenant']->id, [
            'action' => 'settings.updated',
            'properties' => ['reason' => 'Alpha note'],
            'created_at' => CarbonImmutable::parse('2026-10-27 12:00:00', 'UTC'),
        ]);
        $beta = $this->workspace('beta.test');
        $this->row($beta['tenant']->id, [
            'action' => 'beta.only.marker',
            'properties' => ['reason' => 'Beta only note'],
            'created_at' => CarbonImmutable::parse('2026-10-27 12:30:00', 'UTC'),
        ]);

        $this->keep($manager);
        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Alpha note')
            ->assertSee('Settings updated')
            ->assertDontSee('Beta only note')
            ->assertDontSee('beta.only.marker');
    }

    public function test_a_guest_is_sent_to_the_staff_login(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/staff/audit'))
            ->assertRedirect('/staff/login');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(string $tenantId, array $attributes): AuditLog
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)->create(array_merge([
            'tenant_id' => $tenantId,
            'actor_user_id' => null,
            'action' => 'booking.updated',
            'subject_type' => null,
            'subject_id' => null,
            'properties' => [],
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => CarbonImmutable::now('UTC'),
        ], $attributes));
    }

    private function keep(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }
}
