<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class DepartureLocationTimeTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_public_departure_names_the_location_zone_across_daylight_saving(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor Point Marina',
            'timezone' => 'America/New_York',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Harbor night tour',
            'kind' => ServiceKind::Tour,
            'max_participants' => 8,
        ]);
        $spring = $this->departure($workspace['tenant'], $service, '2026-03-08 03:00:00', '2026-03-08 08:00:00', 4, false);
        $fall = $this->departure($workspace['tenant'], $service, '2026-11-01 02:00:00', '2026-11-01 08:00:00', 6, false);
        $evening = $this->departure($workspace['tenant'], $service, '2026-10-28 22:00:00', '2026-10-29 00:00:00', 10, false);
        $private = $this->departure($workspace['tenant'], $service, '2026-10-29 22:00:00', '2026-10-30 00:00:00', null, true);
        $this->departure($workspace['tenant'], $service, '2026-03-06 06:00:00', '2026-03-06 08:00:00', 3, false, DepartureStatus::Cancelled);
        app(TenantManager::class)->forget();

        $creek = $this->workspace('creek.test');
        $creekLocation = Location::factory()->create([
            'tenant_id' => $creek['tenant']->id,
            'name' => 'Creek dock',
            'timezone' => 'America/New_York',
        ]);
        app(TenantManager::class)->set($creek['tenant']);
        $creekService = Service::query()->create([
            'tenant_id' => $creek['tenant']->id,
            'location_id' => $creekLocation->id,
            'name' => 'Creek midnight tour',
            'kind' => ServiceKind::Tour,
        ]);
        $this->departure($creek['tenant'], $creekService, '2026-10-28 22:00:00', '2026-10-29 00:00:00', 2, false);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $before = $this->moneyCounts($workspace['tenant']);

        $form = $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id))
            ->assertOk()
            ->assertSee('Sat, Mar 7, 10:00 PM EST – Sun, Mar 8, 4:00 AM EDT · 4 seats left')
            ->assertSee('Sat, Oct 31, 10:00 PM EDT – Sun, Nov 1, 3:00 AM EST · 6 seats left')
            ->assertSee('Wed, Oct 28, 6:00 PM – 8:00 PM EDT · 10 seats left')
            ->assertSee('Thu, Oct 29, 6:00 PM – 8:00 PM EDT · Private')
            ->assertDontSee('Fri, Mar 6, 1:00 AM')
            ->assertDontSee('Creek midnight tour');

        $html = (string) $form->getContent();
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($spring->id, '/').'"[^>]*>Sat, Mar 7, 10:00 PM EST – Sun, Mar 8, 4:00 AM EDT · 4 seats left<\/option>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($fall->id, '/').'"[^>]*>Sat, Oct 31, 10:00 PM EDT – Sun, Nov 1, 3:00 AM EST · 6 seats left<\/option>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($evening->id, '/').'"[^>]*>Wed, Oct 28, 6:00 PM – 8:00 PM EDT · 10 seats left<\/option>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($private->id, '/').'"[^>]*>Thu, Oct 29, 6:00 PM – 8:00 PM EDT · Private<\/option>/',
            $html,
        );
        $this->assertSame($before, $this->moneyCounts($workspace['tenant']));

        $this->actingAsWorkspace($creek);
        $creekToken = (string) $this->postJson($this->tenantUrl('creek.test', '/api/v1/widget-tokens'), [
            'name' => 'Creek site',
        ])->assertCreated()->json('data.token');
        $this->get($this->tenantUrl('creek.test', '/book/'.$creekToken.'/services/'.$creekService->id))
            ->assertOk()
            ->assertSee('Creek midnight tour')
            ->assertDontSee('Harbor night tour')
            ->assertDontSee('4 seats left');
        $this->get($this->tenantUrl('creek.test', '/book/'.$token.'/services/'.$service->id))->assertNotFound();
    }

    public function test_a_missing_location_zone_uses_the_business_zone_and_utc_stays_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->firstOrFail()->update(['timezone' => 'America/Chicago']);
        $invalid = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Unnamed clock',
            'timezone' => 'Not/AZone',
        ]);
        $utc = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Zulu dock',
            'timezone' => 'UTC',
        ]);
        $invalidService = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $invalid->id,
            'name' => 'Chicago fallback tour',
            'kind' => ServiceKind::Tour,
        ]);
        $utcService = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $utc->id,
            'name' => 'Utc tour',
            'kind' => ServiceKind::Tour,
        ]);
        $this->departure($workspace['tenant'], $invalidService, '2026-10-28 23:00:00', '2026-10-29 01:00:00', 5, false);
        $this->departure($workspace['tenant'], $utcService, '2026-10-28 18:00:00', '2026-10-28 20:00:00', 5, false);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$invalidService->id))
            ->assertOk()
            ->assertSee('Wed, Oct 28, 6:00 PM – 8:00 PM CDT · 5 seats left')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('EDT')
            ->assertDontSee('UTC');
        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$utcService->id))
            ->assertOk()
            ->assertSee('Wed, Oct 28, 6:00 PM – 8:00 PM UTC · 5 seats left')
            ->assertDontSee('CDT')
            ->assertDontSee('EDT');
    }

    public function test_guest_manage_lists_other_departures_in_the_location_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        [$workspace, $service] = $this->guidedTour('America/New_York');
        app(TenantManager::class)->set($workspace['tenant']);
        $booked = $this->departure($workspace['tenant'], $service, '2026-10-28 22:00:00', '2026-10-29 00:00:00', 8, false);
        $this->departure($workspace['tenant'], $service, '2026-03-08 03:00:00', '2026-03-08 08:00:00', 4, false);
        $this->departure($workspace['tenant'], $service, '2026-11-01 02:00:00', '2026-11-01 08:00:00', 6, false);
        app(TenantManager::class)->forget();

        $bookingId = $this->holdTour($workspace, $service, $booked, 'guest-tour');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
        $before = $this->moneyCounts($workspace['tenant']);

        $this->get($this->manageUrl($workspace['tenant'], $bookingId))
            ->assertOk()
            ->assertSee('Wed, Oct 28, 6:00 PM – 8:00 PM EDT')
            ->assertSee('Sat, Mar 7, 10:00 PM EST – Sun, Mar 8, 4:00 AM EDT')
            ->assertSee('Sat, Oct 31, 10:00 PM EDT – Sun, Nov 1, 3:00 AM EST')
            ->assertDontSee('Duration in minutes');

        $this->assertSame($before, $this->moneyCounts($workspace['tenant']));
        app(TenantManager::class)->set($workspace['tenant']);
        $kept = Booking::query()->findOrFail($bookingId);
        $this->assertSame($booked->id, $kept->departure_id);
        $this->assertSame('2026-10-28 22:00:00', $kept->starts_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();

        $other = $this->workspace('creek.test');
        $this->get(str_replace('http://dock.test', 'http://creek.test', $this->manageUrl($workspace['tenant'], $bookingId)))
            ->assertNotFound()
            ->assertDontSee('Harbor night tour');
    }

    public function test_a_refused_reschedule_names_both_zones_when_the_suggestion_crosses_daylight_saving(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 1, 'America/New_York');
        $hour = $shop['service']->durations->first();
        $this->assertInstanceOf(ServiceDuration::class, $hour);
        $twoDay = ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $shop['service']->id,
            'name' => '2 days',
            'quantity' => 48,
            'unit' => 'hours',
            'duration_minutes' => 2880,
        ]);

        foreach ([0, 1, 2, 3, 4, 5, 6] as $weekday) {
            AvailabilitySchedule::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'owner_type' => 'location',
                'owner_id' => $shop['location']->id,
                'weekday' => $weekday,
                'opens_local' => '08:00:00',
                'closes_local' => '18:00:00',
            ]);
        }

        app(TenantManager::class)->forget();

        $bookingId = $this->confirmedRental($workspace, $twoDay, '2026-03-02', '10:00', 'stay-early');
        $this->holdRental($workspace, $hour, '2026-03-07', '10:00', 'block-hour');
        $manage = $this->manageUrl($workspace['tenant'], $bookingId);
        $before = $this->moneyCounts($workspace['tenant']);

        $this->post($this->rescheduleUrl($manage), [
            'date' => '2026-03-07',
            'start' => '10:00',
        ])->assertStatus(409)
            ->assertSee('That time is no longer available.')
            ->assertSee('Other times')
            ->assertSee('Sat, Mar 7, 11:00 AM EST – Mon, Mar 9, 12:00 PM EDT');

        $this->assertSame($before, $this->moneyCounts($workspace['tenant']));
        app(TenantManager::class)->set($workspace['tenant']);
        $kept = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-03-02 15:00:00', $kept->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-04 15:00:00', $kept->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{rules: int, snapshots: int, payments: int, refunds: int, fees: int}
     */
    private function moneyCounts(Tenant $tenant): array
    {
        return [
            'rules' => PriceRule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
        ];
    }

    private function departure(
        Tenant $tenant,
        Service $service,
        string $startsAtUtc,
        string $endsAtUtc,
        ?int $seats,
        bool $private,
        DepartureStatus $status = DepartureStatus::Scheduled,
    ): Departure {
        return Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endsAtUtc, 'UTC'),
            'private' => $private,
            'seats' => $seats,
            'status' => $status,
        ]);
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function guidedTour(string $timezone): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor Point Marina',
            'timezone' => $timezone,
        ]);
        $guide = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Sam (guide)',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Harbor night tour',
            'kind' => ServiceKind::Tour,
            'max_participants' => 8,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $guide->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function holdTour(array $workspace, Service $service, Departure $departure, string $key): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure->id,
            'starts_at' => CarbonImmutable::instance($departure->starts_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => CarbonImmutable::instance($departure->ends_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function confirmedRental(array $workspace, ServiceDuration $duration, string $date, string $start, string $key): string
    {
        $bookingId = $this->holdRental($workspace, $duration, $date, $start, $key);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function holdRental(array $workspace, ServiceDuration $duration, string $date, string $start, string $key): string
    {
        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $response = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$duration->service_id), [
            'date' => $date,
            'start' => $start,
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ])->assertRedirect();
        preg_match('#/checkout/([^?]+)#', (string) $response->headers->get('Location'), $matches);

        return $matches[1];
    }

    private function manageUrl(Tenant $tenant, string $bookingId): string
    {
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.app(WidgetTokens::class)->hostedPlain($tenant).'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();

        return (string) $found->headers->get('Location');
    }

    private function rescheduleUrl(string $manage): string
    {
        $page = $this->get($manage)->assertOk();
        preg_match('/action="([^"]*reschedule[^"]*)"/', (string) $page->getContent(), $action);

        return html_entity_decode($action[1]);
    }
}
