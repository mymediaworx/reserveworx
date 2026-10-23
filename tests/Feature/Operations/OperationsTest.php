<?php

namespace Tests\Feature\Operations;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\RentalCheckout;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_maintenance_flags_a_future_booking_without_cancelling_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '15:00', '16:00', 'ops-1');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/maintenance-windows'), [
            'asset_id' => $asset->id,
            'starts_at' => '2026-06-16T15:30:00Z',
            'ends_at' => '2026-06-16T17:00:00Z',
            'reason' => 'Engine service',
        ])->assertCreated()->assertJsonPath('data.flagged_bookings', 1);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.attention_reason', 'Engine service');

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id), [
            'status' => 'maintenance',
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('confirmed', Booking::query()->findOrFail($bookingId)->status->value);
        app(TenantManager::class)->forget();
    }

    public function test_a_late_return_records_a_fee_and_a_waiver_needs_a_guardian_for_a_minor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['late_fee_per_minute_cents' => 50]);
        app(TenantManager::class)->forget();
        $bookingId = $this->confirmed($service, '18:00', '19:00', 'ops-late');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout'))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-16 19:20:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1_000, RentalCheckout::query()->where('booking_id', $bookingId)->firstOrFail()->late_fee_cents);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/incidents'), [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
        ])->assertCreated();

        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
        ])->assertStatus(422);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $waiver['id'],
            'participant_name' => 'Sam',
            'is_minor' => true,
            'guardian_name' => 'Ada',
        ], ['User-Agent' => 'ReserveWorxTest'])->assertCreated()
            ->assertJsonPath('data.guardian_name', 'Ada')
            ->assertJsonPath('data.content_hash', $waiver['content_hash']);

        app(TenantManager::class)->set($workspace['tenant']);
        $signature = WaiverSignature::query()->firstOrFail();
        $this->assertSame($waiver['content_hash'], $signature->content_hash);
        $this->assertNotNull($signature->ip_address);
        $this->assertSame('ReserveWorxTest', $signature->user_agent);
        app(TenantManager::class)->forget();

        $pdf = $this->get($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers/'.$signature->id));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', (string) $pdf->getContent());
        $this->assertStringContainsString('Sam', (string) $pdf->getContent());
        $this->assertStringContainsString('Ada', (string) $pdf->getContent());
        $this->assertStringContainsString((string) $waiver['content_hash'], (string) $pdf->getContent());
        $this->assertStringContainsString('not an attorney-approved', (string) $pdf->getContent());

        $this->get($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers/not-a-signature'))
            ->assertNotFound();
    }

    private function confirmed(Service $service, string $start, string $end, string $key): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T'.$start.':00Z',
            'ends_at' => '2026-06-16T'.$end.':00Z',
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
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
