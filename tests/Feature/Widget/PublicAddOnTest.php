<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicAddOnTest extends TestCase
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

    public function test_a_guest_adds_equipment_and_the_booking_fee_includes_it(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $bag = $this->catalog('Dry bag', 'addon', 'per_person', 200);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);
        $token = $this->token();
        $page = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($page)->assertOk()->assertDontSee('Equipment and add-ons');

        $form = $this->get($page.'?date=2026-06-15&duration_minutes=60&participants=2')->assertOk();
        $html = (string) $form->getContent();
        $form->assertSee('Equipment and add-ons')
            ->assertSee('Paddle')
            ->assertSee('$5.00 each')
            ->assertSee('up to 4')
            ->assertSee('Cooler')
            ->assertSee('$15.00')
            ->assertSee('Dry bag')
            ->assertSee('$2.00 per person');
        $this->assertLessThan(strpos($html, 'Cooler'), strpos($html, 'name="add_ons['.$paddle.']"'));

        $held = $this->post($page, $this->party('addons-1', 2, [
            $cooler => 1,
            $bag => 1,
            $paddle => 2,
        ]));
        $held->assertRedirect();
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));

        app(TenantManager::class)->set($workspace['tenant']);
        $lines = BookingAddOn::query()->orderBy('name')->get();
        $this->assertSame(['Cooler', 'Dry bag', 'Paddle'], $lines->pluck('name')->all());
        $this->assertSame(1_500, $lines->firstWhere('name', 'Cooler')?->total_cents);
        $this->assertSame(400, $lines->firstWhere('name', 'Dry bag')?->total_cents);
        $this->assertSame(1_000, $lines->firstWhere('name', 'Paddle')?->total_cents);
        $this->assertSame(2, $lines->firstWhere('name', 'Paddle')?->quantity);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(10_000, $snapshot->payload['services_cents']);
        $this->assertSame(2_900, $snapshot->payload['add_ons_cents']);
        $this->assertSame(12_900, $snapshot->payload['basis_cents']);
        $this->assertSame(387, $snapshot->payload['fee_cents']);
        $this->assertSame(13_287, $snapshot->payload['customer_total_cents']);
        app(TenantManager::class)->forget();

        $checkout = $this->get((string) $held->headers->get('Location'))->assertOk();
        $checkout->assertSee('Equipment and add-ons')
            ->assertSee('Hourly boat')
            ->assertSee('Cooler')
            ->assertSee('Dry bag')
            ->assertSee('Paddle × 2')
            ->assertSee('$15.00')
            ->assertSee('$4.00')
            ->assertSee('$10.00')
            ->assertSee('$129.00')
            ->assertSee('Booking fee')
            ->assertSee('$3.87')
            ->assertSee('$132.87');

        URL::forceRootUrl('http://dock.test');
        $confirmation = URL::temporarySignedRoute('widget.confirmation', now()->addDay(), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        URL::forceRootUrl(null);
        $this->get($confirmation)->assertOk()->assertSee('Paddle × 2')->assertSee('$10.00')->assertSee('Dry bag');
    }

    public function test_leaving_add_ons_empty_keeps_the_service_price(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);
        $token = $this->token();

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('addons-empty', 1, [
            $cooler => 0,
            $paddle => 0,
        ]));
        $held->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, BookingAddOn::query()->count());
        $snapshot = PricingSnapshot::query()->firstOrFail();
        $this->assertSame(0, $snapshot->payload['add_ons_cents']);
        $this->assertSame(300, $snapshot->payload['fee_cents']);
        $this->assertSame(10_300, $snapshot->payload['customer_total_cents']);
        app(TenantManager::class)->forget();

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('Equipment and add-ons')
            ->assertSee('$3.00')
            ->assertSee('$103.00');
    }

    public function test_a_bad_add_on_is_refused_and_nothing_is_held(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $other = $this->workspace('beta.test');
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $retired = $this->catalog('Retired cooler', 'addon', 'flat', 900);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/add-ons/'.$retired), [
            'active' => false,
        ])->assertOk();
        $this->actingAsWorkspace($other);
        $foreign = (string) $this->postJson($this->tenantUrl('beta.test', '/api/v1/add-ons'), [
            'name' => 'Other dock paddle',
            'kind' => 'equipment',
            'pricing' => 'each',
            'amount_cents' => 700,
        ])->assertCreated()->json('data.id');
        $token = $this->tokenFor($workspace, 'dock.test');
        $page = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($page.'?date=2026-06-15&duration_minutes=60&participants=1')
            ->assertOk()
            ->assertSee('Cooler')
            ->assertSee('Paddle')
            ->assertDontSee('Retired cooler')
            ->assertDontSee('Other dock paddle');

        $this->post($page, $this->party('addons-retired', 1, [$retired => 1]))
            ->assertStatus(422)
            ->assertSee('That add-on is not available.');
        $this->post($page, $this->party('addons-foreign', 1, [$foreign => 1]))
            ->assertStatus(422)
            ->assertSee('That add-on is not in this workspace.');
        $this->post($page, $this->party('addons-too-many', 1, [$paddle => 5]))
            ->assertStatus(422)
            ->assertSee('Choose between 1 and 4.')
            ->assertSee('value="5"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, BookingAddOn::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_tour_guest_can_add_equipment_before_the_hold(): void
    {
        [$workspace, $service, $departure] = $this->tour('dock.test');
        $this->actingAsWorkspace($workspace);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);
        $token = $this->token();
        $page = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($page)->assertOk()->assertSee('Equipment and add-ons')->assertSee('Paddle')->assertSee('Departure');

        $held = $this->post($page, [
            'departure_id' => $departure->id,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'tour-addon',
            'add_ons' => [$paddle => 2],
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $line = BookingAddOn::query()->firstOrFail();
        $this->assertSame('Paddle', $line->name);
        $this->assertSame(1_000, $line->total_cents);
        $snapshot = PricingSnapshot::query()->firstOrFail();
        $this->assertSame(8_000, $snapshot->payload['services_cents']);
        $this->assertSame(1_000, $snapshot->payload['add_ons_cents']);
        $this->assertSame(9_000, $snapshot->payload['basis_cents']);
        $this->assertSame(270, $snapshot->payload['fee_cents']);
        app(TenantManager::class)->forget();

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee('Paddle × 2')
            ->assertSee('$10.00')
            ->assertSee('$2.70')
            ->assertSee('$92.70');
    }

    /**
     * @param  array<string, int>  $addOns
     * @return array<string, mixed>
     */
    private function party(string $key, int $participants, array $addOns): array
    {
        return [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => $participants,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
            'add_ons' => $addOns,
        ];
    }

    private function catalog(string $name, string $kind, string $pricing, int $amountCents, ?int $maxQuantity = null): string
    {
        $body = [
            'name' => $name,
            'kind' => $kind,
            'pricing' => $pricing,
            'amount_cents' => $amountCents,
        ];

        if ($maxQuantity !== null) {
            $body['max_quantity'] = $maxQuantity;
        }

        $id = $this->postJson($this->tenantUrl('dock.test', '/api/v1/add-ons'), $body)->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function tokenFor(array $workspace, string $host): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    private function token(): string
    {
        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(string $host): array
    {
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        return [$workspace, $service];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
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
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure}
     */
    private function tour(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $guide = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Sam',
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
        $starts = CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC');
        $departure = Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $starts,
            'ends_at' => $starts->addHours(2),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Tour',
            'kind' => 'base',
            'amount_cents' => 8_000,
            'window' => 'always',
        ])->assertCreated();

        return [$workspace, $service, $departure];
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }
}
