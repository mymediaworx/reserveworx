<?php

namespace Tests\Feature\Widget;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
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
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestLocationTimeZoneTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_guest_pages_show_the_location_zone_including_a_daylight_saving_span(): void
    {
        [$workspace, $service, $token] = $this->rental();
        $this->businessZone($workspace['tenant'], 'America/Chicago');
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book.'?date=2026-06-15&duration_minutes=60&participants=1');
        $form->assertOk()
            ->assertSee('Times are in America/New_York.')
            ->assertSee('data-location-zone', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Times are in America/Chicago.')
            ->assertDontSee('9:00 AM CDT');
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="10:00"/', (string) $form->getContent()));

        $held = $this->post($book, [
            'date' => '2026-06-15',
            'start' => '10:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'zone-june',
        ])->assertRedirect();
        $review = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($review);

        app(TenantManager::class)->set($workspace['tenant']);
        $before = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload;
        $prices = PriceRule::query()->orderBy('id')->pluck('amount_cents')->all();
        $payments = Payment::query()->count();
        $refunds = Refund::query()->count();
        $fees = PlatformFee::query()->count();
        $snapshots = PricingSnapshot::query()->count();
        app(TenantManager::class)->forget();

        $this->get($review)
            ->assertOk()
            ->assertSee('Mon, Jun 15, 10:00 AM – 11:00 AM EDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('9:00 AM CDT')
            ->assertDontSee('Times are in America/Chicago.');

        $confirmation = $this->confirmationUrl('dock.test', $token, $bookingId);
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Mon, Jun 15, 10:00 AM – 11:00 AM EDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('9:00 AM CDT');

        $spring = $this->span($workspace, $service, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'spring@example.com');
        $this->get($this->confirmationUrl('dock.test', $token, $spring->id))
            ->assertOk()
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertDontSee('9:00 AM CST')
            ->assertDontSee('10:00 AM CDT');

        $fall = $this->span($workspace, $service, '2026-10-31 14:00:00', '2026-11-02 14:00:00', 'fall@example.com');
        $this->get($this->confirmationUrl('dock.test', $token, $fall->id))
            ->assertOk()
            ->assertSee('Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST')
            ->assertDontSee('9:00 AM CDT')
            ->assertDontSee('8:00 AM CST');

        $this->workspace('creek.test');
        $this->get(str_replace('http://dock.test', 'http://creek.test', $confirmation))
            ->assertNotFound()
            ->assertDontSee('10:00 AM EDT')
            ->assertDontSee('Mon, Jun 15, 10:00 AM');

        app(TenantManager::class)->set($workspace['tenant']);
        $kept = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-06-15 14:00:00', $kept->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 15:00:00', $kept->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($before, PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload);
        $this->assertSame($prices, PriceRule::query()->orderBy('id')->pluck('amount_cents')->all());
        $this->assertSame($payments, Payment::query()->count());
        $this->assertSame($refunds, Refund::query()->count());
        $this->assertSame($fees, PlatformFee::query()->count());
        $this->assertSame($snapshots, PricingSnapshot::query()->count());
        $this->assertSame('2026-03-07 15:00:00', $spring->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $spring->fresh()->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 14:00:00', $fall->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $fall->fresh()->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    public function test_a_tour_departure_list_names_the_location_zone(): void
    {
        [$workspace, $service, $departure, $token] = $this->tour();
        $this->businessZone($workspace['tenant'], 'America/Chicago');

        $form = $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id));
        $form->assertOk()
            ->assertSee('Times are in America/New_York.')
            ->assertSee('Tue, Jun 16, 6:00 PM – 8:00 PM EDT · 8 seats left')
            ->assertSee('data-location-zone', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('value="'.$departure->id.'"', false)
            ->assertDontSee('5:00 PM CDT')
            ->assertDontSee('Times are in America/Chicago.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Booking::query()->count());
        app(TenantManager::class)->forget();
    }

    private function businessZone(Tenant $tenant, string $zone): void
    {
        app(TenantManager::class)->set($tenant);
        TenantSetting::query()->where('tenant_id', $tenant->id)->update(['timezone' => $zone]);
        $this->assertSame($zone, TenantSetting::query()->where('tenant_id', $tenant->id)->value('timezone'));
        app(TenantManager::class)->forget();
    }

    private function confirmationUrl(string $host, string $token, string $bookingId): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::temporarySignedRoute('widget.confirmation', now()->addDay(), [
                'token' => $token,
                'booking' => $bookingId,
            ]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function span(array $workspace, Service $service, string $startUtc, string $endUtc, string $email): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada',
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => ServiceKind::Rental,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Hour',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'always',
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure, 3: string}
     */
    private function tour(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $guide = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guide',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Sunset paddle',
            'kind' => ServiceKind::Tour,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $guide->id,
            'quantity' => 1,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Per paddler',
            'kind' => 'per_person',
            'amount_cents' => 5500,
            'window' => 'always',
        ]);
        $start = CarbonImmutable::parse('2026-06-16 18:00:00', 'America/New_York')->utc();
        $departure = Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Tours',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $departure, $token];
    }
}
