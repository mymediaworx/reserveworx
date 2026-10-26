<?php

namespace Tests\Feature\Web;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Settings;
use App\Livewire\Staff\Setup\Locations;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Notifications\BookingUpdateMail;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BusinessTimezoneTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_or_manager_saves_the_business_time_zone_and_staff_cannot(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $setting = TenantSetting::query()->firstOrFail();
        $this->assertSame('America/New_York', $setting->timezone);
        $before = $setting->only(['fee_payer', 'hold_minutes', 'direct_fee_bps']);

        $this->get($this->tenantUrl('alpha.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Business time zone')
            ->assertSee('Save time zone');

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('timezone', 'America/New_York')
            ->set('timezone', 'Not/AZone')
            ->call('saveTimezone')
            ->assertHasErrors(['timezone']);

        Livewire::test(Settings::class)
            ->set('timezone', 'America/Chicago')
            ->call('saveTimezone')
            ->assertHasNoErrors()
            ->assertSee('Business time zone saved.');

        $setting->refresh();
        $this->assertSame('America/Chicago', $setting->timezone);
        $this->assertSame($before['fee_payer'], $setting->fee_payer);
        $this->assertSame($before['hold_minutes'], $setting->hold_minutes);
        $this->assertSame($before['direct_fee_bps'], $setting->direct_fee_bps);

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(['timezone' => 'America/New_York'], $audit->properties['before'] ?? null);
        $this->assertSame(['timezone' => 'America/Chicago'], $audit->properties['after'] ?? null);
        $this->assertSame(
            'America/New_York',
            TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->value('timezone'),
        );

        $manager = $this->signedIn('manager.test', 'manager');
        Livewire::test(Settings::class)
            ->set('timezone', 'America/Denver')
            ->call('saveTimezone')
            ->assertHasNoErrors();
        $this->assertSame('America/Denver', TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->value('timezone'));
        $this->assertSame(
            'America/Chicago',
            TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $owner['tenant']->id)->value('timezone'),
        );

        $staff = $this->signedIn('staff.test', 'staff');
        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Business time zone')
            ->assertDontSee('Save time zone');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->set('timezone', 'America/Chicago')
            ->call('saveTimezone')
            ->assertForbidden();

        $this->useTenant($staff['tenant']);
        $this->assertSame('America/New_York', TenantSetting::query()->where('tenant_id', $staff['tenant']->id)->value('timezone'));
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
    }

    public function test_a_new_location_starts_in_the_business_time_zone(): void
    {
        $workspace = $this->signedIn('alpha.test');
        TenantSetting::query()->firstOrFail()->update(['timezone' => 'America/Chicago']);

        Livewire::test(Locations::class)
            ->assertSet('timezone', 'America/Chicago')
            ->set('name', 'East dock')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('America/Chicago', Location::query()->where('name', 'East dock')->firstOrFail()->timezone);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/locations'), [
            'name' => 'West dock',
        ])->assertCreated()->assertJsonPath('data.timezone', 'America/Chicago');

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/locations'), [
            'name' => 'Utc dock',
            'timezone' => 'UTC',
        ])->assertCreated()->assertJsonPath('data.timezone', 'UTC');
    }

    public function test_guests_and_staff_see_the_location_time_zone(): void
    {
        $workspace = $this->signedIn('alpha.test');
        [$shop, $booking] = $this->confirmedVisit($workspace, 'America/Denver');
        $service = $this->tour($workspace['tenant'], $shop['location']);

        $this->manage($workspace['tenant'], $booking)
            ->assertOk()
            ->assertSee('Mon, Jul 20, 12:00 PM – 1:00 PM MDT')
            ->assertDontSee('Mon, Jul 20, 6:00 PM – 7:00 PM UTC');

        $this->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-07-20'))
            ->assertOk()
            ->assertSee('12:00–1:00 PM')
            ->assertSee('America/Denver')
            ->assertDontSee('6:00–7:00 PM');

        $this->get($this->bookingPage($workspace['tenant'], $service))
            ->assertOk()
            ->assertSee('Mon, Jul 20, 12:00 PM')
            ->assertDontSee('Mon, Jul 20, 6:00 PM');
    }

    public function test_a_blank_location_zone_uses_the_business_time_zone(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $workspace = $this->signedIn('alpha.test');
        [$shop, $booking] = $this->confirmedVisit($workspace, 'America/Denver');
        $service = $this->tour($workspace['tenant'], $shop['location']);
        TenantSetting::query()->firstOrFail()->update(['timezone' => 'America/Chicago']);
        $shop['location']->timezone = '';
        $shop['location']->save();

        $this->manage($workspace['tenant'], $booking->fresh() ?? $booking)
            ->assertOk()
            ->assertSee('Mon, Jul 20, 1:00 PM – 2:00 PM CDT')
            ->assertDontSee('Mon, Jul 20, 6:00 PM – 7:00 PM UTC')
            ->assertDontSee('Mon, Jul 20, 12:00 PM – 1:00 PM MDT');

        $this->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-07-20'))
            ->assertOk()
            ->assertSee('1:00–2:00 PM')
            ->assertSee('America/Chicago')
            ->assertDontSee('6:00–7:00 PM');

        $this->get($this->bookingPage($workspace['tenant'], $service))
            ->assertOk()
            ->assertSee('Mon, Jul 20, 1:00 PM')
            ->assertDontSee('Mon, Jul 20, 6:00 PM');

        $this->useTenant($workspace['tenant']);
        $fresh = $booking->fresh(['service', 'location']);
        $this->assertInstanceOf(Booking::class, $fresh);
        $mail = app(BookingUpdateMail::class)->compose(new NotificationHook([
            'kind' => 'booking.cancelled',
            'payload' => ['booking_id' => $fresh->id],
        ]), $fresh, 'Alpha Kayaks');
        $this->assertIsArray($mail);
        $this->assertStringContainsString('Mon, Jul 20, 1:00 PM', $mail['lines'][0]);
        $this->assertStringNotContainsString('6:00 PM', $mail['lines'][0]);

        Carbon::setTestNow(Carbon::parse('2026-07-20 03:30:00', 'UTC'));
        $this->get($this->tenantUrl('alpha.test', '/staff/load-sheet'))
            ->assertOk()
            ->assertSee('2026-07-19 (America/Chicago)')
            ->assertDontSee('2026-07-20 (UTC)');

        Carbon::setTestNow(Carbon::parse('2026-07-20 16:30:00', 'UTC'));
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->useTenant($workspace['tenant']);
        $hooks = NotificationHook::query()->where('kind', 'booking.reminder')->get();
        $this->assertGreaterThan(0, $hooks->count());

        foreach ($hooks as $hook) {
            $this->assertSame('America/Chicago', $hook->payload['timezone'] ?? null);
            $this->assertStringContainsString('2026-07-20T13:00:00-05:00', (string) ($hook->payload['local_starts_at'] ?? ''));
        }
    }

    public function test_a_location_whose_zone_is_utc_still_shows_utc(): void
    {
        $workspace = $this->signedIn('alpha.test');
        TenantSetting::query()->firstOrFail()->update(['timezone' => 'America/Chicago']);
        [, $booking] = $this->confirmedVisit($workspace, 'UTC');

        $this->manage($workspace['tenant'], $booking)
            ->assertOk()
            ->assertSee('Mon, Jul 20, 6:00 PM – 7:00 PM UTC')
            ->assertDontSee('Mon, Jul 20, 1:00 PM – 2:00 PM CDT');

        $this->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-07-20'))
            ->assertOk()
            ->assertSee('6:00–7:00 PM')
            ->assertSee('UTC · UTC');
    }

    public function test_a_set_location_zone_still_sets_the_load_sheet_day(): void
    {
        $workspace = $this->signedIn('alpha.test');
        TenantSetting::query()->firstOrFail()->update(['timezone' => 'America/Chicago']);
        $this->confirmedVisit($workspace, 'America/Denver');
        Carbon::setTestNow(Carbon::parse('2026-07-20 03:30:00', 'UTC'));

        $this->get($this->tenantUrl('alpha.test', '/staff/load-sheet'))
            ->assertOk()
            ->assertSee('2026-07-19 (America/Denver)')
            ->assertDontSee('2026-07-20 (UTC)');
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    private function signedIn(string $host, string $role = 'owner'): array
    {
        $workspace = $this->workspace($host, $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     * @return array{0: array{location: Location, service: Service}, 1: Booking}
     */
    private function confirmedVisit(array $workspace, string $timezone): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 4, $timezone);
        $start = CarbonImmutable::parse('2026-07-20 18:00:00', 'UTC');
        $booking = app(Allocator::class)->confirm(app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $shop['service']->id,
            $start,
            $start->addHour(),
            1,
            'visit-'.$timezone.'-'.$workspace['tenant']->id,
            'Ada Lovelace',
            'ada@example.com',
        )));

        return [$shop, $booking];
    }

    private function tour(Tenant $tenant, Location $location): Service
    {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Sunset paddle',
            'kind' => ServiceKind::Tour,
        ]);
        $start = CarbonImmutable::parse('2026-07-20 18:00:00', 'UTC');
        Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);

        return $service;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function manage(Tenant $tenant, Booking $booking): TestResponse
    {
        URL::forceRootUrl('http://'.$this->hostOf($tenant));

        return $this->get(URL::signedRoute('widget.manage', [
            'token' => app(WidgetTokens::class)->hostedPlain($tenant),
            'booking' => $booking->id,
        ]));
    }

    private function bookingPage(Tenant $tenant, Service $service): string
    {
        return $this->tenantUrl($this->hostOf($tenant), '/book/'.app(WidgetTokens::class)->hostedPlain($tenant).'/services/'.$service->id);
    }

    private function hostOf(Tenant $tenant): string
    {
        $host = $tenant->domains()->value('hostname');
        $this->assertIsString($host);

        return $host;
    }
}
