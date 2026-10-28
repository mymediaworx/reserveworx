<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Departure;
use App\Models\DepartureMutation;
use App\Models\Domain;
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

class DepartureIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_same_departure_key_schedules_and_cancels_once(): void
    {
        [$workspace, $tour, $rental] = $this->services();
        $this->actingAsWorkspace($workspace);
        $money = $this->money($workspace['tenant']);
        $schedule = [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-01T15:00:00Z',
            'ends_at' => '2026-07-01T17:00:00Z',
            'seats' => 4,
        ];

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-07-01T15:00:00Z',
            'ends_at' => '2026-07-01T17:00:00Z',
        ], [
            'Idempotency-Key' => 'schedule-once',
        ])->assertStatus(422)->assertJsonValidationErrors('service_id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'ends_at' => '2026-07-01T17:00:00Z',
            'idempotency_key' => 'schedule-once',
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), $schedule, [
            'Idempotency-Key' => str_repeat('k', 81),
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), $schedule, [
            'Idempotency-Key' => 'schedule-once',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.seats', 4)
            ->json('data');
        $this->assertIsArray($first);
        $this->assertIsString($first['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-01T15:00:00+00:00',
            'ends_at' => '2026-07-01T17:00:00+00:00',
            'private' => false,
            'seats' => 4,
            'idempotency_key' => 'schedule-once',
        ])->assertCreated()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.status', 'scheduled');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-01T15:00:00Z',
            'ends_at' => '2026-07-01T17:00:00Z',
            'seats' => 5,
        ], [
            'Idempotency-Key' => 'schedule-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $plain = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-02T15:00:00Z',
            'ends_at' => '2026-07-02T17:00:00Z',
            'seats' => 4,
        ])->assertCreated()->json('data');
        $this->assertIsArray($plain);
        $this->assertIsString($plain['id']);
        $again = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-02T15:00:00Z',
            'ends_at' => '2026-07-02T17:00:00Z',
            'seats' => 4,
        ])->assertCreated()->json('data');
        $this->assertIsArray($again);
        $this->assertIsString($again['id']);
        $this->assertNotSame($plain['id'], $again['id']);

        $exact = str_repeat('d', 80);
        $long = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-03T15:00:00Z',
            'ends_at' => '2026-07-03T17:00:00Z',
            'seats' => 4,
        ], [
            'Idempotency-Key' => $exact,
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');
        $this->assertIsArray($long);
        $this->assertIsString($long['id']);

        $cancelUrl = $this->tenantUrl('dock.test', '/api/v1/departures/'.$first['id'].'/cancel');
        $this->postJson($cancelUrl, [], [
            'Idempotency-Key' => 'cancel-once',
        ])->assertOk()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.status', 'cancelled');
        $this->postJson($cancelUrl, [
            'idempotency_key' => 'cancel-once',
        ])->assertOk()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.status', 'cancelled');

        $occupied = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-04T15:00:00Z',
            'ends_at' => '2026-07-04T17:00:00Z',
            'seats' => 6,
        ])->assertCreated()->json('data');
        $this->assertIsArray($occupied);
        $this->assertIsString($occupied['id']);
        $this->assertSame($money, $this->money($workspace['tenant']));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $tour->id,
            'departure_id' => $occupied['id'],
            'starts_at' => '2026-07-04T15:00:00Z',
            'ends_at' => '2026-07-04T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'party-on-departure',
        ])->assertCreated();
        $afterBooking = $this->money($workspace['tenant']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$occupied['id'].'/cancel'), [], [
            'Idempotency-Key' => 'still-booked',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Cancel the bookings on this departure first.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$plain['id'].'/cancel'), [], [
            'Idempotency-Key' => 'still-booked',
        ])->assertOk()
            ->assertJsonPath('data.id', $plain['id'])
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$again['id'].'/cancel'))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$again['id'].'/cancel'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That departure is already cancelled.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$occupied['id'].'/cancel'), [], [
            'Idempotency-Key' => 'cancel-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/departures/'.$first['id'].'/cancel'), [], [
            'Idempotency-Key' => 'cancel-from-other',
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('cancelled', Departure::query()->findOrFail($first['id'])->status->value);
        $this->assertSame('scheduled', Departure::query()->findOrFail($occupied['id'])->status->value);
        $this->assertSame('cancelled', Departure::query()->findOrFail($plain['id'])->status->value);
        $this->assertSame('cancelled', Departure::query()->findOrFail($again['id'])->status->value);
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.scheduled')->where('subject_id', $first['id'])->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.cancelled')->where('subject_id', $first['id'])->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.cancelled')->where('subject_id', $again['id'])->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'departure.cancelled')->where('subject_id', $occupied['id'])->count());
        $this->assertSame(
            ['cancel-once', 'still-booked'],
            DepartureMutation::query()->where('action', 'cancel')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        $this->assertSame(
            [$exact, 'schedule-once'],
            DepartureMutation::query()->where('action', 'schedule')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        $this->assertSame(0, DepartureMutation::query()->where('departure_id', $again['id'])->count());
        $this->assertSame($afterBooking, $this->money($workspace['tenant']));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame(0, Departure::query()->count());
        $this->assertSame(0, DepartureMutation::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        [$workspace, $tour] = $this->services();
        $this->actingAsWorkspace($workspace);
        $body = [
            'service_id' => $tour->id,
            'starts_at' => '2026-07-05T15:00:00Z',
            'ends_at' => '2026-07-05T17:00:00Z',
            'seats' => 4,
        ];

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            ...$body,
            'idempotency_key' => 'body-key',
        ], [
            'Idempotency-Key' => 'header-key',
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');
        $this->assertIsArray($created);
        $this->assertIsString($created['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), $body, [
            'Idempotency-Key' => 'header-key',
        ])->assertCreated()->assertJsonPath('data.id', $created['id']);

        $separate = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            ...$body,
            'idempotency_key' => 'body-key',
        ])->assertCreated()->json('data');
        $this->assertIsArray($separate);
        $this->assertIsString($separate['id']);
        $this->assertNotSame($created['id'], $separate['id']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.scheduled')->where('subject_id', $created['id'])->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.scheduled')->where('subject_id', $separate['id'])->count());
        $this->assertSame(
            ['body-key', 'header-key'],
            DepartureMutation::query()->where('action', 'schedule')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
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

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Service}
     */
    private function services(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guide',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $tour = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Harbor tour',
            'kind' => 'tour',
            'max_participants' => 8,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $tour->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        $rental = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $tour, $rental];
    }
}
