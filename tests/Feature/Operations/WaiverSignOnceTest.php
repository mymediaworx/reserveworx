<?php

namespace Tests\Feature\Operations;

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
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaiverSignOnceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_booking_with_nobody_named_keeps_the_first_signature(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, 'waiver-once');
        $money = $this->money($workspace['tenant']);

        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data');
        $this->assertIsArray($waiver);

        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers');
        $payload = [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
            'guardian_name' => 'Ada',
        ];

        $this->postJson($url, $payload, [
            'Idempotency-Key' => str_repeat('k', 81),
        ])->assertStatus(422);

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
            'idempotency_key' => 'needs-guardian',
        ])->assertStatus(422);

        $first = $this->postJson($url, $payload, [
            'Idempotency-Key' => 'sign-once',
            'User-Agent' => 'ReserveWorxTest',
        ])->assertCreated()
            ->assertJsonPath('data.participant_name', 'Sam')
            ->assertJsonPath('data.guardian_name', 'Ada')
            ->assertJsonPath('data.content_hash', $waiver['content_hash'])
            ->json('data');
        $this->assertIsArray($first);

        $this->postJson($url, $payload, [
            'Idempotency-Key' => 'sign-once',
            'User-Agent' => 'DifferentAgent',
        ])->assertCreated()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.participant_name', 'Sam');

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Someone else',
            'is_minor' => false,
            'idempotency_key' => 'sign-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => '  Sam  ',
            'is_minor' => true,
            'guardian_name' => 'Ada',
            'idempotency_key' => 'body-only',
        ], ['User-Agent' => 'DifferentAgent'])->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Bea',
            'is_minor' => false,
        ], ['User-Agent' => 'DifferentAgent'])->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
            'guardian_name' => 'Ada',
            'idempotency_key' => 'needs-guardian',
        ])->assertOk()->assertJsonPath('data.id', $first['id']);

        $revised = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the revised risks of this rental.',
        ])->assertCreated()->json('data');
        $this->assertIsArray($revised);
        $this->assertNotSame($waiver['id'], $revised['id']);

        $second = $this->postJson($url, [
            'waiver_version_id' => $revised['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
            'guardian_name' => 'Ada',
        ])->assertCreated()
            ->assertJsonPath('data.content_hash', $revised['content_hash'])
            ->json('data');
        $this->assertIsArray($second);
        $this->assertNotSame($first['id'], $second['id']);

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $foreign = $this->postJson($this->tenantUrl('other.test', '/api/v1/waiver-versions'), [
            'name' => 'Other waiver',
            'body' => 'This waiver belongs to another workspace.',
        ])->assertCreated()->json('data');
        $this->assertIsArray($foreign);

        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $foreign['id'],
            'participant_name' => 'Pat',
            'is_minor' => false,
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        $this->postJson($url, [
            'waiver_version_id' => $foreign['id'],
            'participant_name' => 'Pat',
            'is_minor' => false,
        ])->assertStatus(422);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, WaiverSignature::query()->count());
        $stored = WaiverSignature::query()->findOrFail($first['id']);
        $this->assertNull($stored->participant_id);
        $this->assertSame('Sam', $stored->participant_name);
        $this->assertTrue($stored->is_minor);
        $this->assertSame('Ada', $stored->guardian_name);
        $this->assertSame($waiver['content_hash'], $stored->content_hash);
        $this->assertSame('ReserveWorxTest', $stored->user_agent);
        $this->assertNotNull($stored->ip_address);
        $this->assertSame($revised['id'], WaiverSignature::query()->findOrFail($second['id'])->waiver_version_id);
        $this->assertSame(3, BookingMutation::query()->where('action', 'sign_waiver')->count());
        $this->assertSame($money, $this->money($workspace['tenant']));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame(0, WaiverSignature::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, 'waiver-header');
        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data');
        $this->assertIsArray($waiver);

        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers');
        $payload = [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => false,
            'idempotency_key' => 'body-key',
        ];

        $first = $this->postJson($url, $payload, [
            'Idempotency-Key' => 'header-key',
        ])->assertCreated()->json('data');
        $this->assertIsArray($first);

        $this->postJson($url, [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => false,
        ], ['Idempotency-Key' => 'header-key'])->assertCreated()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $first['id']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, WaiverSignature::query()->count());
        $this->assertSame(
            ['body-key', 'header-key'],
            BookingMutation::query()->where('action', 'sign_waiver')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
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

    private function confirmed(Service $service, string $key): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T15:00:00Z',
            'ends_at' => '2026-06-16T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

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
