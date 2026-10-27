<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
use App\Enums\FeePayer;
use App\Enums\ScheduleOwnerType;
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
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicTimePickerPriceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 13:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_each_rental_start_shows_the_all_in_price_for_that_clock(): void
    {
        [$workspace, $service, $token] = $this->peakRental('dock.test');
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);
        $times = $book.'?date=2026-06-15&duration_minutes=60&participants=1';

        $form = $this->get($times);
        $form->assertOk()
            ->assertSee('Start time')
            ->assertSee('10:00 AM')
            ->assertSee('2:00 PM')
            ->assertSee('$103.00')
            ->assertSee('$164.80')
            ->assertSee('Booking fee included')
            ->assertSee('.slots, .slots label, .slots .slot-price { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }', false)
            ->assertSee('select[name="departure_id"] { width: 100%; max-width: 100%; min-width: 0; overflow-wrap: anywhere; }', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('$100.00')
            ->assertDontSee('$160.00');

        $html = (string) $form->getContent();
        $this->assertMatchesRegularExpression(
            '/<input type="radio" name="start" value="10:00"[^>]*>\s*<span>10:00 AM<\/span>\s*<span class="slot-price">\$103\.00 <span class="optional">Booking fee included<\/span><\/span>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input type="radio" name="start" value="14:00"[^>]*>\s*<span>2:00 PM<\/span>\s*<span class="slot-price">\$164\.80 <span class="optional">Booking fee included<\/span><\/span>/',
            $html,
        );
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());

        $held = $this->post($book, [
            'date' => '2026-06-15',
            'start' => '14:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'peak-time-price',
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $payload = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail()->payload;
        $this->assertSame(16_000, $payload['services_cents']);
        $this->assertSame(480, $payload['fee_cents']);
        $this->assertSame(0, $payload['tax_cents']);
        $this->assertSame(16_480, $payload['customer_total_cents']);
        app(TenantManager::class)->forget();

        $again = $this->get($times)->assertOk();
        $againHtml = (string) $again->getContent();
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="14:00"[^>]*disabled/', $againHtml));
        $this->assertMatchesRegularExpression(
            '/value="14:00"[^>]*>\s*<span>2:00 PM<\/span>\s*<span class="slot-price">\$164\.80 <span class="optional">Booking fee included<\/span><\/span>\s*<span class="slot-note">Booked<\/span>/',
            $againHtml,
        );
        $this->assertSame(1, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());

        $this->workspace('other.test');
        $this->get($this->tenantUrl('other.test', '/book/'.$token.'/services/'.$service->id.'?date=2026-06-15&duration_minutes=60&participants=1'))->assertNotFound();
    }

    public function test_operator_absorbed_start_times_omit_the_booking_fee_line(): void
    {
        [, $service, $token] = $this->peakRental('dock.test', FeePayer::OperatorAbsorbed);
        $times = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'?date=2026-06-15&duration_minutes=60&participants=1');

        $form = $this->get($times);
        $form->assertOk()
            ->assertSee('$100.00')
            ->assertSee('$160.00')
            ->assertDontSee('Booking fee included')
            ->assertDontSee('$103.00')
            ->assertDontSee('$164.80');

        $html = (string) $form->getContent();
        $this->assertMatchesRegularExpression(
            '/<input type="radio" name="start" value="10:00"[^>]*>\s*<span>10:00 AM<\/span>\s*<span class="slot-price">\$100\.00<\/span>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input type="radio" name="start" value="14:00"[^>]*>\s*<span>2:00 PM<\/span>\s*<span class="slot-price">\$160\.00<\/span>/',
            $html,
        );
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_start_with_no_price_omits_the_amount(): void
    {
        [, $service, $token] = $this->peakRental('dock.test', FeePayer::CustomerPaid, false);
        $times = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'?date=2026-06-15&duration_minutes=60&participants=1');

        $form = $this->get($times)->assertOk()->assertSee('10:00 AM')->assertSee('2:00 PM');
        $this->assertDoesNotMatchRegularExpression('/class="slot-price"/', (string) $form->getContent());
        $form->assertDontSee('$0.00');
        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_tour_without_options_shows_the_all_in_price_on_each_departure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 16:00:00', 'UTC'));
        [, $service, $token, $sunset] = $this->paddleTour('dock.test');
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book);
        $form->assertOk()
            ->assertSee('Wed, Oct 28, 6:00 PM EDT · 10 seats left · $56.65 · Booking fee included')
            ->assertSee('Wed, Oct 28, 10:00 AM EDT · 6 seats left · $56.65 · Booking fee included')
            ->assertSee('10 seats left')
            ->assertSee('6 seats left');
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());

        $party = $this->get($book.'?participants=2')->assertOk();
        $party->assertSee('Wed, Oct 28, 6:00 PM EDT · 10 seats left · $113.30 · Booking fee included');
        $party->assertDontSee('$56.65');
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());

        $this->assertNotNull($sunset->id);
    }

    public function test_a_tour_with_options_keeps_the_price_on_the_option(): void
    {
        [, $service, $departure, $token] = $this->tourWithOptions('dock.test');
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book)->assertOk()
            ->assertSee('Harbor (1 h 30 min) EUR 139.05')
            ->assertSee('Booking fee included')
            ->assertSee('8 seats left');

        $html = (string) $form->getContent();
        $this->assertSame(1, preg_match('/<option value="'.preg_quote($departure->id, '/').'"[^>]*>([^<]*)<\/option>/', $html, $matches));
        $this->assertStringContainsString('8 seats left', $matches[1]);
        $this->assertStringNotContainsString('EUR', $matches[1]);
        $this->assertStringNotContainsString('Booking fee', $matches[1]);
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string}
     */
    private function peakRental(string $host, FeePayer $payer = FeePayer::CustomerPaid, bool $priced = true): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $boat = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['fee_payer' => $payer]);
        AvailabilitySchedule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'owner_type' => ScheduleOwnerType::Location,
            'owner_id' => $location->id,
            'weekday' => CarbonImmutable::parse('2026-06-15', 'America/New_York')->dayOfWeek,
            'opens_local' => '10:00:00',
            'closes_local' => '17:00:00',
        ]);
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
            'resource_id' => $boat->id,
            'quantity' => 1,
        ]);

        if ($priced) {
            PriceRule::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'service_id' => $service->id,
                'name' => 'Off peak',
                'kind' => 'base',
                'amount_cents' => 10_000,
                'window' => 'always',
            ]);
            PriceRule::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'service_id' => $service->id,
                'name' => 'Afternoon',
                'kind' => 'base',
                'amount_cents' => 16_000,
                'window' => 'always',
                'local_start_minute' => 14 * 60,
                'local_end_minute' => 16 * 60,
            ]);
        }

        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string, 3: Departure}
     */
    private function paddleTour(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Sunset paddle tour',
            'kind' => ServiceKind::Tour,
            'min_participants' => 4,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Per paddler',
            'kind' => 'per_person',
            'amount_cents' => 5_500,
            'window' => 'always',
        ]);
        $sunsetStart = CarbonImmutable::parse('2026-10-28 18:00:00', 'America/New_York')->utc();
        $morningStart = CarbonImmutable::parse('2026-10-28 10:00:00', 'America/New_York')->utc();
        $sunset = Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $sunsetStart,
            'ends_at' => $sunsetStart->copy()->addHours(2),
            'private' => false,
            'seats' => 10,
            'status' => DepartureStatus::Scheduled,
        ]);
        Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $morningStart,
            'ends_at' => $morningStart->copy()->addMinutes(90),
            'private' => false,
            'seats' => 6,
            'status' => DepartureStatus::Scheduled,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Tours',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token, $sunset];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure, 3: string}
     */
    private function tourWithOptions(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['currency' => 'EUR']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Sunset paddle',
            'kind' => ServiceKind::Tour,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Per paddler',
            'kind' => 'per_person',
            'amount_cents' => 5_500,
            'window' => 'always',
        ]);
        ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Harbor',
            'duration_minutes' => 90,
            'price_override_cents' => 8_000,
        ]);
        ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Cove',
            'duration_minutes' => 90,
            'price_override_cents' => 4_000,
        ]);
        $start = Carbon::parse('2026-06-16 15:00:00', 'UTC');
        $departure = Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Tours',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $departure, $token];
    }
}
