<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
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
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicContactPhoneTest extends TestCase
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

    public function test_a_guest_can_leave_an_optional_phone_on_a_rental(): void
    {
        [$workspace, $service, $token] = $this->rental();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book.'?date=2026-06-15&duration_minutes=60&participants=1');
        $form->assertOk()
            ->assertSee('Phone', false)
            ->assertSee('(optional)', false)
            ->assertSee('name="customer_phone"', false)
            ->assertSee('input[name="customer_phone"]', false);
        $html = (string) $form->getContent();
        $this->assertSame(1, preg_match('/<input name="customer_phone"[^>]*>/', $html, $field));
        $this->assertStringNotContainsString('required', $field[0]);

        $held = $this->post($book, $this->party('phone-1', [
            'customer_phone' => '  555-0100  ',
        ]));
        $held->assertRedirect();
        $review = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($review);
        $this->get($review)
            ->assertOk()
            ->assertSee('555-0100')
            ->assertSee('data-customer-phone="1"', false)
            ->assertSee('$103.00');

        URL::forceRootUrl('http://dock.test');
        $confirmation = URL::temporarySignedRoute('widget.confirmation', now()->addDay(), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        $this->get($confirmation)->assertOk()->assertSee('555-0100')->assertDontSee('555-0199');

        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertSee('555-0100')
            ->assertSee('data-customer-phone="1"', false);

        $replaced = $this->post($book, $this->party('phone-2', [
            'start' => '16:00',
            'customer_phone' => '555-0199',
        ]))->assertRedirect();
        $blank = $this->post($book, $this->party('phone-3', [
            'start' => '17:00',
            'customer_email' => 'bea@example.com',
            'customer_phone' => '   ',
        ]))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('555-0100', Customer::query()->where('email', 'ada@example.com')->firstOrFail()->phone);
        $this->assertNull(Customer::query()->where('email', 'bea@example.com')->firstOrFail()->phone);
        $first = $this->snapshot($bookingId);
        $second = $this->snapshot($this->bookingId((string) $replaced->headers->get('Location')));
        $third = $this->snapshot($this->bookingId((string) $blank->headers->get('Location')));
        $this->assertSame(10300, $first['customer_total_cents']);
        $this->assertSame($first['customer_total_cents'], $second['customer_total_cents']);
        $this->assertSame($first['fee_cents'], $second['fee_cents']);
        $this->assertSame($first['tax_cents'], $third['tax_cents']);
        $this->assertSame($first['customer_total_cents'], $third['customer_total_cents']);
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());

        $other = $this->workspace('other.test');
        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('other.test', '/staff/bookings/'.$bookingId))->assertNotFound();
        $this->assertSame(1, Customer::withoutGlobalScope(TenantScope::class)->where('phone', '555-0100')->count());
    }

    public function test_a_guest_can_leave_a_phone_on_a_tour(): void
    {
        [$workspace, $service, $departure, $token] = $this->tour();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($book)
            ->assertOk()
            ->assertSee('name="customer_phone"', false)
            ->assertSee('name="departure_id"', false);

        $held = $this->post($book, [
            'departure_id' => $departure->id,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada-tour@example.com',
            'customer_phone' => ' 555-0142 ',
            'idempotency_key' => 'tour-phone',
        ])->assertRedirect();

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee('555-0142');

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $this->assertSame($departure->id, $booking->departure_id);
        $this->assertSame('555-0142', $booking->customer?->phone);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_phone_longer_than_255_characters_is_refused(): void
    {
        [$workspace, $service, $token] = $this->rental();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);
        $phone = str_repeat('5', 256);

        $this->post($book, $this->party('too-long', [
            'customer_phone' => $phone,
        ]))
            ->assertStatus(422)
            ->assertSee('255')
            ->assertSee($phone, false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Payment::query()->count());
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
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ], $overrides);
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(string $bookingId): array
    {
        $payload = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload;

        return [
            'customer_total_cents' => (int) $payload['customer_total_cents'],
            'fee_cents' => (int) $payload['fee_cents'],
            'tax_cents' => (int) $payload['tax_cents'],
        ];
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
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
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

        return [$workspace, $service, $departure, $token];
    }
}
