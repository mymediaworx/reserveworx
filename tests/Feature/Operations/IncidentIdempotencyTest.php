<?php

namespace Tests\Feature\Operations;

use App\Models\Asset;
use App\Models\BookingMutation;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class IncidentIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_same_incident_key_returns_the_first_report(): void
    {
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, 'incident-once', '15:00', '16:00');
        $otherBookingId = $this->confirmed($service, 'incident-other', '17:00', '18:00');
        $money = $this->money($workspace['tenant']);
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/incidents');
        $payload = [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
        ];

        $this->postJson($url, $payload, [
            'Idempotency-Key' => str_repeat('k', 81),
        ])->assertStatus(422);

        $this->postJson($url, [
            'occurred_at' => '2026-06-16T19:10:00Z',
            'idempotency_key' => 'needs-summary',
        ])->assertStatus(422);

        $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => 'missing-boat',
            'idempotency_key' => 'missing-boat',
        ])->assertStatus(422);

        $first = $this->postJson($url, $payload, [
            'Idempotency-Key' => 'incident-once',
        ])->assertCreated()->json('data');
        $this->assertIsArray($first);
        $this->assertIsString($first['id']);

        $this->postJson($url, $payload, [
            'Idempotency-Key' => 'incident-once',
        ])->assertCreated()->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'summary' => 'A different scrape.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
            'idempotency_key' => 'incident-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:20:00Z',
            'asset_id' => $asset->id,
            'idempotency_key' => 'incident-once',
        ])->assertStatus(409);

        $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'idempotency_key' => 'incident-once',
        ])->assertStatus(409);

        $retried = $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
            'idempotency_key' => 'needs-summary',
        ])->assertCreated()->json('data');
        $this->assertIsArray($retried);
        $this->assertNotSame($first['id'], $retried['id']);

        $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
            'idempotency_key' => 'needs-summary',
        ])->assertCreated()->assertJsonPath('data.id', $retried['id']);

        $afterMissingBoat = $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
            'idempotency_key' => 'missing-boat',
        ])->assertCreated()->json('data');
        $this->assertIsArray($afterMissingBoat);
        $this->assertNotSame($first['id'], $afterMissingBoat['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$otherBookingId.'/incidents'), $payload, [
            'Idempotency-Key' => 'incident-once',
        ])->assertStatus(409);

        $unkeyed = $this->postJson($url, [
            'summary' => 'A second scrape.',
            'occurred_at' => '2026-06-16T19:30:00Z',
        ])->assertCreated()->json('data');
        $this->assertIsArray($unkeyed);
        $this->assertNotSame($first['id'], $unkeyed['id']);

        $unkeyedAgain = $this->postJson($url, [
            'summary' => 'A second scrape.',
            'occurred_at' => '2026-06-16T19:30:00Z',
        ])->assertCreated()->json('data');
        $this->assertIsArray($unkeyedAgain);
        $this->assertNotSame($unkeyed['id'], $unkeyedAgain['id']);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $first['id'])
            ->assertJsonPath('data.0.summary', 'Scraped the dock.')
            ->assertJsonPath('data.0.asset_id', $asset->id);

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/incidents'), $payload, [
            'Idempotency-Key' => 'incident-once',
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(5, Incident::query()->count());
        $stored = Incident::query()->findOrFail($first['id']);
        $this->assertSame('Scraped the dock.', $stored->summary);
        $this->assertSame($asset->id, $stored->asset_id);
        $this->assertSame($bookingId, $stored->booking_id);
        $this->assertNull($stored->damage_cents);
        $this->assertSame(0, Incident::query()->where('booking_id', $otherBookingId)->count());
        $this->assertSame(
            ['incident-once', 'missing-boat', 'needs-summary'],
            BookingMutation::query()->where('action', 'incident')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        $this->assertSame($money, $this->money($workspace['tenant']));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame(0, Incident::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, 'incident-header', '15:00', '16:00');
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/incidents');
        $payload = [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'idempotency_key' => 'body-key',
        ];

        $first = $this->postJson($url, $payload, [
            'Idempotency-Key' => 'header-key',
        ])->assertCreated()->json('data');
        $this->assertIsArray($first);

        $this->postJson($url, [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
        ], ['Idempotency-Key' => 'header-key'])->assertCreated()
            ->assertJsonPath('data.id', $first['id']);

        $second = $this->postJson($url, $payload)->assertCreated()->json('data');
        $this->assertIsArray($second);
        $this->assertNotSame($first['id'], $second['id']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Incident::query()->count());
        $this->assertSame(
            ['body-key', 'header-key'],
            BookingMutation::query()->where('action', 'incident')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{prices: int, payments: int, refunds: int, fees: int, fee_refunds: int, taxes: int}
     */
    private function money(Tenant $tenant): array
    {
        app(TenantManager::class)->set($tenant);

        $counts = [
            'prices' => PriceRule::query()->count(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
            'taxes' => LocationTaxRule::query()->count(),
        ];

        app(TenantManager::class)->forget();

        return $counts;
    }

    private function confirmed(Service $service, string $key, string $start, string $end): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T'.$start.':00Z',
            'ends_at' => '2026-06-16T'.$end.':00Z',
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
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

        return [$workspace, $service, $asset];
    }
}
