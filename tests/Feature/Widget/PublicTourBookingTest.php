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
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\TourMinimum;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicTourBookingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-26 16:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_public_tour_booking_joins_the_departure_and_flags_it_below_minimum(): void
    {
        [$workspace, $service, $sunset, $morning, $token] = $this->paddleTour();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $form = $this->get($book)
            ->assertOk()
            ->assertSee('Departure')
            ->assertSee('Wed, Oct 28, 6:00 PM')
            ->assertSee('10 seats left')
            ->assertSee('Wed, Oct 28, 10:00 AM')
            ->assertSee('6 seats left')
            ->assertDontSee('Duration in minutes')
            ->assertDontSee('No upcoming departures');
        $formHtml = (string) $form->getContent();
        $this->assertStringContainsString('name="departure_id"', $formHtml);
        $this->assertStringContainsString('value="'.$sunset->id.'"', $formHtml);
        $this->assertStringNotContainsString('name="duration_minutes"', $formHtml);
        $this->assertStringNotContainsString('name="start"', $formHtml);

        $this->actingAs($workspace['user']);
        $before = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-10-28'))
            ->assertOk()
            ->assertSee('0/6');
        $beforeSunset = $this->chip((string) $before->getContent(), $sunset->id);
        $this->assertStringContainsString('0/10', $beforeSunset);
        $this->assertStringNotContainsString('data-below-minimum', $beforeSunset);
        $this->assertStringNotContainsString('data-below-minimum="'.$morning->id.'"', (string) $before->getContent());

        $firstId = $this->confirmAtPickup($book, $this->party('sunset-1', [
            'date' => '2026-10-28',
            'start' => '18:00',
            'duration_minutes' => 60,
            'participants' => 1,
        ]));
        $secondId = $this->confirmAtPickup($book, $this->party('sunset-2', [
            'departure_id' => $sunset->id,
            'participants' => 1,
        ]));

        app(TenantManager::class)->set($workspace['tenant']);
        $first = Booking::query()->findOrFail($firstId);
        $second = Booking::query()->findOrFail($secondId);
        $this->assertSame($sunset->id, $first->departure_id);
        $this->assertSame($sunset->id, $second->departure_id);
        $this->assertSame('confirmed', $first->status->value);
        $this->assertSame('confirmed', $second->status->value);
        $this->assertSame('due_at_pickup', $first->payment_status->value);
        $this->assertTrue(CarbonImmutable::instance($first->starts_at)->equalTo(CarbonImmutable::instance($sunset->starts_at)));
        $this->assertTrue(CarbonImmutable::instance($first->ends_at)->equalTo(CarbonImmutable::instance($sunset->ends_at)));
        $this->assertSame(120, (int) round(CarbonImmutable::instance($first->starts_at)->diffInMinutes(CarbonImmutable::instance($first->ends_at))));
        $this->assertSame(120, (int) round(CarbonImmutable::instance($second->starts_at)->diffInMinutes(CarbonImmutable::instance($second->ends_at))));
        $this->assertSame(2, $sunset->fresh()?->headcount());
        $this->assertSame(0, $morning->fresh()?->headcount());
        $counts = Departure::headcountsFor(collect([$sunset, $morning]));
        $this->assertSame(2, $counts[$sunset->id] ?? null);
        $this->assertArrayNotHasKey($morning->id, $counts);
        $summary = app(TourMinimum::class)->summarize(
            collect([$sunset->fresh(), $morning->fresh()]),
            CarbonImmutable::parse('2026-10-26 16:00:00', 'UTC'),
        );
        $this->assertSame(2, $summary[$sunset->id]['participants']);
        $this->assertTrue($summary[$sunset->id]['belowMinimum']);
        $this->assertSame(0, $summary[$morning->id]['participants']);
        $this->assertFalse($summary[$morning->id]['belowMinimum']);
        $this->assertSame(2, Booking::query()->count());
        app(TenantManager::class)->forget();

        $this->get($book)
            ->assertOk()
            ->assertSee('8 seats left')
            ->assertSee('6 seats left');

        $this->actingAs($workspace['user']);
        $calendar = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-10-28'))
            ->assertOk()
            ->assertSee('Below minimum')
            ->assertSee('0/6');
        $sunsetChip = $this->chip((string) $calendar->getContent(), $sunset->id);
        $this->assertStringContainsString('2 of 4', $sunsetChip);
        $this->assertStringContainsString('data-below-minimum="'.$sunset->id.'"', $sunsetChip);
        $this->assertStringNotContainsString('0/10', $sunsetChip);
        $this->assertStringNotContainsString('data-below-minimum="'.$morning->id.'"', (string) $calendar->getContent());

        $home = $this->get($this->tenantUrl('dock.test', '/staff'))
            ->assertOk()
            ->assertSee('Tours below minimum')
            ->assertSee('2 of 4');
        $this->assertStringContainsString('data-below-minimum="'.$sunset->id.'"', (string) $home->getContent());

        $manifest = $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-10-28'))
            ->assertOk()
            ->assertSee('Below minimum')
            ->assertSee('2 of 4');
        $this->assertStringContainsString('data-below-minimum="'.$sunset->id.'"', (string) $manifest->getContent());
        $this->assertStringNotContainsString('data-below-minimum="'.$morning->id.'"', (string) $manifest->getContent());

        $this->post($book, $this->party('sunset-full', [
            'departure_id' => $sunset->id,
            'participants' => 9,
        ]))->assertStatus(409)->assertSee('That departure is full.');
        $this->post($book, $this->party('no-departure', [
            'date' => '2026-10-29',
            'start' => '18:00',
            'duration_minutes' => 60,
            'participants' => 1,
        ]))->assertStatus(422)->assertSee('Choose a departure.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Booking::query()->count());
        $this->assertSame(2, $sunset->fresh()?->headcount());
        app(TenantManager::class)->forget();
    }

    public function test_a_party_larger_than_the_departure_does_not_open_a_free_slot(): void
    {
        [$workspace, $service, $sunset, $morning, $token] = $this->paddleTour();
        $book = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->post($book, $this->party('morning-full', [
            'date' => '2026-10-28',
            'start' => '10:00',
            'duration_minutes' => 60,
            'participants' => 7,
        ]))->assertStatus(409)->assertSee('That departure is full.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, $morning->fresh()?->headcount());
        $this->assertSame(0, $sunset->fresh()?->headcount());
        $this->assertSame([], Departure::headcountsFor(collect([$sunset, $morning])));
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return array<string, string|int>
     */
    private function party(string $key, array $overrides): array
    {
        return array_merge([
            'participants' => 1,
            'customer_name' => 'Ada Paddle',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ], $overrides);
    }

    /**
     * @param  array<string, string|int>  $payload
     */
    private function confirmAtPickup(string $book, array $payload): string
    {
        $held = $this->post($book, $payload);
        $held->assertRedirect();
        $location = (string) $held->headers->get('Location');
        $checkout = $this->get($location)->assertOk()->assertSee('Pay at pickup');
        $this->post($this->formAction((string) $checkout->getContent(), 'Pay at pickup'))->assertRedirect();

        return $this->bookingId($location);
    }

    private function formAction(string $html, string $button): string
    {
        $pattern = '/<form method="post" action="([^"]+)"[^>]*>\s*<button[^>]*>'.preg_quote($button, '/').'/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing the '.$button.' form.');

        return html_entity_decode($matches[1]);
    }

    private function chip(string $html, string $id): string
    {
        $pattern = '/<a\b[^>]*data-departure="'.preg_quote($id, '/').'"[\s\S]*?<\/a>/';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing the departure chip.');

        return $matches[0];
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches);

        return $matches[1];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Departure, 3: Departure, 4: string}
     */
    private function paddleTour(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor Point Marina',
            'timezone' => 'America/New_York',
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
            'name' => 'Sunset paddle tour',
            'kind' => ServiceKind::Tour,
            'min_participants' => 4,
            'max_participants' => 12,
            'waitlist_enabled' => false,
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
        $sunsetStart = CarbonImmutable::parse('2026-10-28 18:00:00', 'America/New_York')->utc();
        $morningStart = CarbonImmutable::parse('2026-10-28 10:00:00', 'America/New_York')->utc();
        $sunset = $this->departure($workspace['tenant'], $service, $sunsetStart, $sunsetStart->addHours(2), 10, DepartureStatus::Scheduled);
        $this->departure($workspace['tenant'], $service, $sunsetStart, $sunsetStart->addHours(2), 10, DepartureStatus::Cancelled);
        $morning = $this->departure($workspace['tenant'], $service, $morningStart, $morningStart->addMinutes(90), 6, DepartureStatus::Scheduled);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/settings'), [
            'pay_at_pickup' => true,
        ])->assertOk();
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [$workspace, $service, $sunset, $morning, $token];
    }

    private function departure(
        Tenant $tenant,
        Service $service,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
        int $seats,
        DepartureStatus $status,
    ): Departure {
        return Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'private' => false,
            'seats' => $seats,
            'status' => $status,
        ]);
    }
}
