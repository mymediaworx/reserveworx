<?php

namespace Tests\Feature\Payments;

use App\Models\Asset;
use App\Models\BookingMutation;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PricingQuoteIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_same_pricing_key_returns_the_first_quote(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'quote-a', '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z');
        $otherBookingId = $this->hold($service, 'quote-b', '2026-06-15T17:00:00Z', '2026-06-15T18:00:00Z');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/promo-codes'), [
            'code' => 'save10',
            'percent_off' => 10,
        ])->assertCreated();
        $money = $this->money($workspace['tenant']);
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing');
        $key = str_repeat('q', 80);
        $payload = [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
        ];

        $this->postJson($url, $payload, [
            'Idempotency-Key' => str_repeat('q', 81),
        ])->assertStatus(422);

        $this->postJson($url, [
            'promo_code' => 'NOPE',
            'idempotency_key' => 'bad-promo',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'That promo code is not valid.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, PricingSnapshot::query()->count());
        $this->assertSame(0, BookingMutation::query()->where('action', 'pricing')->count());
        app(TenantManager::class)->forget();

        $first = $this->postJson($url, $payload, [
            'Idempotency-Key' => $key,
        ])->assertOk()->json('data');
        $this->assertIsArray($first);
        $this->assertIsString($first['id']);
        $this->assertSame(10_000, $first['payload']['services_cents']);
        $this->assertSame(1_000, $first['payload']['discount_cents']);
        $this->assertSame(270, $first['payload']['fee_cents']);
        $this->assertSame(9_270, $first['payload']['customer_total_cents']);

        $this->postJson($url, $payload, [
            'Idempotency-Key' => $key,
        ])->assertOk()->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 825,
            'idempotency_key' => $key,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($url, [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
            'deposit_percent' => 50,
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->postJson($url, [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
            'channel' => 'third_party',
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->postJson($url, [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
            'add_ons' => [['id' => 'life-jacket', 'quantity' => 1]],
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $retried = $this->postJson($url, [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
            'idempotency_key' => 'bad-promo',
        ])->assertOk()->json('data');
        $this->assertIsArray($retried);
        $this->assertSame($first['id'], $retried['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$otherBookingId.'/pricing'), $payload, [
            'Idempotency-Key' => $key,
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, PricingSnapshot::query()->where('booking_id', $otherBookingId)->count());
        app(TenantManager::class)->forget();

        $unkeyed = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$otherBookingId.'/pricing'), [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
        ])->assertOk()->json('data');
        $this->assertIsArray($unkeyed);
        $this->assertIsString($unkeyed['id']);
        $this->assertNotSame($first['id'], $unkeyed['id']);

        $unkeyedAgain = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$otherBookingId.'/pricing'), [
            'promo_code' => 'NOPE',
        ])->assertOk()->json('data');
        $this->assertIsArray($unkeyedAgain);
        $this->assertSame($unkeyed['id'], $unkeyedAgain['id']);

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/pricing'), $payload, [
            'Idempotency-Key' => $key,
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, PricingSnapshot::query()->count());
        $this->assertSame(0, PricingSnapshot::query()->where('booking_id', $otherBookingId)->where('id', '!=', $unkeyed['id'])->count());
        $stored = PricingSnapshot::query()->findOrFail($first['id']);
        $this->assertSame($bookingId, $stored->booking_id);
        $this->assertSame(10_000, $stored->payload['services_cents']);
        $this->assertSame(1_000, $stored->payload['discount_cents']);
        $this->assertSame(270, $stored->payload['fee_cents']);
        $this->assertSame(9_270, $stored->payload['customer_total_cents']);
        $this->assertSame(
            ['bad-promo', $key],
            BookingMutation::query()->where('action', 'pricing')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        $this->assertSame($money, $this->money($workspace['tenant']));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame(0, PricingSnapshot::query()->count());
        $this->assertSame(0, BookingMutation::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'quote-header');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing');

        $first = $this->postJson($url, [
            'tax_basis_points' => 0,
            'idempotency_key' => 'body-key',
        ], [
            'Idempotency-Key' => 'header-key',
        ])->assertOk()->json('data');
        $this->assertIsArray($first);

        $this->postJson($url, [
            'tax_basis_points' => 0,
        ], ['Idempotency-Key' => 'header-key'])->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $second = $this->postJson($url, [
            'tax_basis_points' => 0,
            'idempotency_key' => 'body-key',
        ])->assertOk()->json('data');
        $this->assertIsArray($second);
        $this->assertSame($first['id'], $second['id']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, PricingSnapshot::query()->count());
        $this->assertSame(
            ['body-key', 'header-key'],
            BookingMutation::query()->where('action', 'pricing')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
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

    private function hold(Service $service, string $suffix, string $starts = '2026-06-15T15:00:00Z', string $ends = '2026-06-15T16:00:00Z'): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 2,
            'idempotency_key' => 'pay-'.$suffix,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);

        return $bookingId;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
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

        return [$workspace, $service];
    }
}
