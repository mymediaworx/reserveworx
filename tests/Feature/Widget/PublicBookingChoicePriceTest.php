<?php

namespace Tests\Feature\Widget;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
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
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicBookingChoicePriceTest extends TestCase
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
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_each_length_shows_its_own_price_in_the_tenant_currency(): void
    {
        [$workspace, $service, $token, $twoHours] = $this->lengthsInEuros();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book);
        $form->assertOk()
            ->assertSee('Length')
            ->assertSee('1 hour (1 hour) EUR 51.50')
            ->assertSee('2 hours (2 hours) EUR 82.40')
            ->assertSee('Booking fee included')
            ->assertSee('name="duration_id"', false)
            ->assertSee('.length-list, .length-list label, .length-list span', false)
            ->assertSee('.option-list label, .length-list label { flex-wrap: wrap; align-items: flex-start; }', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('1 hour (1 hour) EUR 82.40')
            ->assertDontSee('2 hours (2 hours) EUR 51.50')
            ->assertDontSee('$51.50')
            ->assertDontSee('$82.40')
            ->assertDontSee('Duration in minutes');

        $html = (string) $form->getContent();
        $this->assertMatchesRegularExpression(
            '/<label>\s*<input type="radio" name="duration_id" value="'.preg_quote($twoHours->id, '/').'"[^>]*>\s*<span>2 hours \(2 hours\) EUR 82\.40 <span class="optional">Booking fee included<\/span>/',
            $html,
        );
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());

        $held = $this->post($book, [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_id' => $twoHours->id,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'length-price',
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $payload = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail()->payload;
        $this->assertSame('EUR', PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail()->currency);
        $this->assertSame(8_000, $payload['services_cents']);
        $this->assertSame(240, $payload['fee_cents']);
        $this->assertSame(0, $payload['tax_cents']);
        $this->assertSame(8_240, $payload['customer_total_cents']);
        $this->assertSame(120, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        app(TenantManager::class)->forget();

        $this->workspace('other.test');
        $this->get($this->tenantUrl('other.test', '/book/'.$token.'/services/'.$service->id))->assertNotFound();
    }

    public function test_each_service_option_shows_its_own_price_in_the_tenant_currency(): void
    {
        [$workspace, $service, $departure, $token, $harbor] = $this->tourOptionsInEuros();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book);
        $form->assertOk()
            ->assertSee('Option')
            ->assertSee('Harbor (1 h 30 min) EUR 139.05')
            ->assertSee('Cove (1 h 30 min) EUR 97.85')
            ->assertSee('Booking fee included')
            ->assertSee('.option-list, .option-list label, .option-list span', false)
            ->assertSee('.option-list label, .length-list label { flex-wrap: wrap; align-items: flex-start; }', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Harbor (1 h 30 min) EUR 97.85')
            ->assertDontSee('Cove (1 h 30 min) EUR 139.05')
            ->assertDontSee('$139.05')
            ->assertDontSee('$82.40');

        $html = (string) $form->getContent();
        $this->assertMatchesRegularExpression(
            '/<label>\s*<input type="radio" name="variant_id" value="'.preg_quote($harbor->id, '/').'"[^>]*>\s*<span>Harbor \(1 h 30 min\) EUR 139\.05 <span class="optional">Booking fee included<\/span>/',
            $html,
        );
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());

        $held = $this->post($book, [
            'departure_id' => $departure->id,
            'variant_id' => $harbor->id,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada-tour@example.com',
            'idempotency_key' => 'option-price',
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId((string) $held->headers->get('Location')));
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $payload = $snapshot->payload;
        $this->assertSame('EUR', $snapshot->currency);
        $this->assertSame(19_000, $payload['services_cents']);
        $this->assertSame(570, $payload['fee_cents']);
        $this->assertSame(0, $payload['tax_cents']);
        $this->assertSame(19_570, $payload['customer_total_cents']);
        $this->assertSame($harbor->id, $booking->service_variant_id);
        $this->assertSame(120, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
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
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string, 3: ServiceDuration}
     */
    private function lengthsInEuros(): array
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
        TenantSetting::query()->update(['currency' => 'EUR']);
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
        ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => '1 hour',
            'quantity' => 1,
            'unit' => 'hours',
            'duration_minutes' => 60,
        ]);
        $twoHours = ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => '2 hours',
            'quantity' => 2,
            'unit' => 'hours',
            'duration_minutes' => 120,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => '1 hour',
            'kind' => 'duration',
            'amount_cents' => 5_000,
            'window' => 'always',
            'duration_minutes' => 60,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => '2 hours',
            'kind' => 'duration',
            'amount_cents' => 8_000,
            'window' => 'always',
            'duration_minutes' => 120,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $token, $twoHours];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure, 3: string, 4: ServiceVariant}
     */
    private function tourOptionsInEuros(): array
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
        TenantSetting::query()->update(['currency' => 'EUR']);
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
            'amount_cents' => 5_500,
            'window' => 'always',
        ]);
        $harbor = ServiceVariant::query()->create([
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
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Tours',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $departure, $token, $harbor];
    }
}
