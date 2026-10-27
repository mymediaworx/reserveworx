<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
use App\Enums\ResourceStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicServiceOptionTest extends TestCase
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

    public function test_a_guest_holds_the_option_they_chose(): void
    {
        [$workspace, $service, $token, $single, $tandem] = $this->rentalWithOptions();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book.'?date=2026-06-15&participants=1&variant_id='.$tandem->id);
        $form->assertOk()
            ->assertSee('Option')
            ->assertSee('Single')
            ->assertSee('Tandem')
            ->assertSee('$103.00')
            ->assertSee('$82.40')
            ->assertSee('Booking fee included')
            ->assertSee('.option-list, .option-list label, .option-list span', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Duration in minutes');

        $held = $this->post($book, $this->party('tandem-1', [
            'variant_id' => $tandem->id,
        ]));
        $held->assertRedirect();
        $review = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($review);
        $this->get($review)
            ->assertOk()
            ->assertSee('Tandem')
            ->assertSee('data-service-option="'.$tandem->id.'"', false)
            ->assertSee('$82.40')
            ->assertDontSee('Single');

        URL::forceRootUrl('http://dock.test');
        $confirmation = URL::temporarySignedRoute('widget.confirmation', now()->addDay(), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Tandem')
            ->assertSee('data-service-option="'.$tandem->id.'"', false);

        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertSee('Tandem')
            ->assertSee('data-service-option="'.$tandem->id.'"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame($tandem->id, $booking->service_variant_id);
        $this->assertSame('2026-06-15 15:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 17:00:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        $payload = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload;
        $this->assertSame(8_000, $payload['services_cents']);
        $this->assertSame(240, $payload['fee_cents']);
        $this->assertSame(8_240, $payload['customer_total_cents']);
        $this->assertSame($tandem->id, $payload['variant_id']);
        $this->assertSame('Tandem', $payload['variant_name']);
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertNotSame($single->id, $booking->service_variant_id);
        app(TenantManager::class)->forget();

        $other = $this->workspace('other.test');
        $this->get($this->tenantUrl('other.test', '/book/'.$token.'/services/'.$service->id))->assertNotFound();
        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('other.test', '/staff/bookings/'.$bookingId))->assertNotFound();
        URL::forceRootUrl('http://other.test');
        $foreignConfirmation = URL::temporarySignedRoute('widget.confirmation', now()->addDay(), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        $this->get($foreignConfirmation)->assertNotFound();
    }

    public function test_a_missing_or_foreign_option_holds_nothing(): void
    {
        [$workspace, $service, $token] = $this->rentalWithOptions();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);
        $foreign = $this->foreignOption();

        $this->post($book, $this->party('missing'))
            ->assertStatus(422)
            ->assertSee('Choose an option.');
        $this->post($book, $this->party('foreign', [
            'variant_id' => $foreign,
        ]))
            ->assertStatus(422)
            ->assertSee('That option is not on this service.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, Hold::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_open_times_follow_the_option_boat(): void
    {
        [$workspace, $service, $token, $tandem, $boatB] = $this->optionOnAnotherBoat();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book.'?date=2026-06-15&participants=1&variant_id='.$tandem->id);
        $form->assertOk()->assertSee('3:00 PM');
        $html = (string) $form->getContent();
        $this->assertStringContainsString('name="start" value="15:00" required', $html);
        $this->assertStringNotContainsString('name="start" value="15:00" required disabled', $html);

        $held = $this->post($book, $this->party('boat-b', [
            'variant_id' => $tandem->id,
        ]))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $hold = Hold::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($boatB->id, $hold->asset_id);
        $this->assertSame($tandem->id, $booking->service_variant_id);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_full_option_can_join_the_waitlist(): void
    {
        [$workspace, $service, $token, $tandem] = $this->optionOnAnotherBoat(true);
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->post($book, $this->party('held-option', [
            'variant_id' => $tandem->id,
        ]))->assertRedirect();

        $full = $this->get($book.'?date=2026-06-15&participants=1&variant_id='.$tandem->id);
        $full->assertOk();
        $this->assertStringContainsString('name="start" value="15:00" required disabled', (string) $full->getContent());

        $this->post($book, $this->party('wait-option', [
            'variant_id' => $tandem->id,
            'customer_email' => 'bea@example.com',
        ]))
            ->assertStatus(409)
            ->assertSee('Join waitlist')
            ->assertSee('name="variant_id"', false);

        $this->post($book.'/waitlist', $this->party('wait-option', [
            'variant_id' => $tandem->id,
            'customer_email' => 'bea@example.com',
        ]))->assertOk()->assertSee('You are on the waitlist');

        app(TenantManager::class)->set($workspace['tenant']);
        $entry = WaitlistEntry::query()->where('idempotency_key', 'wait-option')->firstOrFail();
        $this->assertSame($tandem->id, $entry->service_variant_id);
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_service_without_an_option_still_asks_for_a_length(): void
    {
        [$workspace, $service, $token] = $this->plainRental();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($book)
            ->assertOk()
            ->assertSee('Duration in minutes')
            ->assertDontSee('name="variant_id"', false)
            ->assertSee('Choose a date, length, and party size to see the open start times.');

        $held = $this->post($book, $this->party('plain-1', [
            'duration_minutes' => 60,
        ]))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $this->assertNull($booking->service_variant_id);
        $payload = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail()->payload;
        $this->assertSame(10_000, $payload['services_cents']);
        $this->assertSame(300, $payload['fee_cents']);
        $this->assertSame(10_300, $payload['customer_total_cents']);
        $this->assertSame(1, PriceRule::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_tour_stores_the_option_and_keeps_the_departure_clock(): void
    {
        [$workspace, $service, $departure, $token, $option] = $this->tourWithOption();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($book)
            ->assertOk()
            ->assertSee('Option')
            ->assertSee('Harbor')
            ->assertSee('name="variant_id"', false)
            ->assertDontSee('Duration in minutes')
            ->assertDontSee('$82.40');

        $this->post($book, [
            'departure_id' => $departure->id,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada-tour@example.com',
            'idempotency_key' => 'tour-missing',
        ])->assertStatus(422)->assertSee('Choose an option.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        app(TenantManager::class)->forget();

        $held = $this->post($book, [
            'departure_id' => $departure->id,
            'variant_id' => $option->id,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada-tour@example.com',
            'idempotency_key' => 'tour-option',
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $this->assertSame($option->id, $booking->service_variant_id);
        $this->assertSame($departure->id, $booking->departure_id);
        $this->assertTrue($booking->starts_at->equalTo($departure->starts_at));
        $this->assertTrue($booking->ends_at->equalTo($departure->ends_at));
        $this->assertSame(120, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return array<string, string|int>
     */
    private function party(string $key, array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-06-15',
            'start' => '15:00',
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ], $overrides);
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string, 3: ServiceVariant, 4: ServiceVariant}
     */
    private function rentalWithOptions(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $boat = Asset::factory()->create([
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
            'resource_id' => $boat->id,
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
        $single = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Single',
            'duration_minutes' => 60,
        ]);
        $tandem = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Tandem',
            'duration_minutes' => 120,
            'price_override_cents' => 8_000,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token, $single, $tandem];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string}
     */
    private function plainRental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $boat = Asset::factory()->create([
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
            'resource_id' => $boat->id,
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
     * Boat A is the service boat and is in maintenance. The option uses Boat B.
     *
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string, 3: ServiceVariant, 4: Asset}
     */
    private function optionOnAnotherBoat(bool $waitlist = false): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Kayaks',
        ]);
        $boatA = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat A',
            'status' => ResourceStatus::Maintenance,
        ]);
        $boatB = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat B',
            'status' => ResourceStatus::Active,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => ServiceKind::Rental,
            'waitlist_enabled' => $waitlist,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $boatA->id,
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
        $tandem = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Tandem',
            'duration_minutes' => 60,
        ]);
        ServiceVariantResource::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_variant_id' => $tandem->id,
            'resource_category_id' => $category->id,
            'resource_type' => 'asset',
            'resource_id' => $boatB->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token, $tandem, $boatB];
    }

    private function foreignOption(): string
    {
        $workspace = $this->workspace('other.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Other boat',
            'kind' => ServiceKind::Rental,
        ]);
        $variant = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Foreign',
            'duration_minutes' => 60,
        ]);
        app(TenantManager::class)->forget();

        return $variant->id;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure, 3: string, 4: ServiceVariant}
     */
    private function tourWithOption(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
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
        $option = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Harbor',
            'duration_minutes' => 90,
            'price_override_cents' => 8_000,
        ]);
        $start = CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC');
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

        return [$workspace, $service, $departure, $token, $option];
    }
}
