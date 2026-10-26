<?php

namespace Tests\Feature\Allocation;

use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingMutation;
use App\Models\CustomerDataExport;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;
use ZipArchive;

class BookingMutationIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_repeating_confirm_status_and_cancel_does_not_run_them_again(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $held = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'hold-once',
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'ada@example.com',
        ])->assertCreated();
        $id = $held->json('data.id');
        $this->assertIsString($id);

        $confirmed = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), [
            'idempotency_key' => 'confirm-once',
        ])->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), [
            'idempotency_key' => 'confirm-once',
        ])->assertOk()->assertJsonPath('data.id', $confirmed->json('data.id'));

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id), [
            'operational_status' => 'checked_in',
            'idempotency_key' => 'arrive-once',
        ])->assertOk()->assertJsonPath('data.operational_status', 'checked_in');

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id), [
            'operational_status' => 'in_progress',
            'idempotency_key' => 'arrive-once',
        ])->assertStatus(409);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id), [
            'operational_status' => 'checked_in',
            'idempotency_key' => 'arrive-once',
        ])->assertOk()->assertJsonPath('data.operational_status', 'checked_in');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/cancel'), [
            'idempotency_key' => 'cancel-once',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/cancel'), [
            'idempotency_key' => 'cancel-once',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Allocation::query()->where('booking_id', $id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.confirmed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.operational_status')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.cancelled')->count());
        $this->assertSame('checked_in', Booking::query()->findOrFail($id)->operational_status->value);
        $this->assertSame(3, BookingMutation::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_same_reschedule_key_refuses_a_different_time(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $id = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/reschedule'), [
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'idempotency_key' => 'move-once',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-06-15T19:00:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/reschedule'), [
            'starts_at' => '2026-06-15T20:00:00Z',
            'ends_at' => '2026-06-15T21:00:00Z',
            'idempotency_key' => 'move-once',
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/reschedule'), [
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'idempotency_key' => 'move-once',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-06-15T19:00:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.rescheduled')->count());
        $this->assertSame('2026-06-15 19:00:00', Booking::query()->findOrFail($id)->ends_at?->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    public function test_a_refused_extension_can_be_retried_with_the_same_key(): void
    {
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $first = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z');
        $later = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Later',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $later->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $later->id,
            'starts_at' => '2026-06-15T17:00:00Z',
            'ends_at' => '2026-06-15T18:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'later-hold',
        ])->assertCreated();
        $secondId = Booking::withoutGlobalScope(TenantScope::class)->where('idempotency_key', 'later-hold')->value('id');
        $this->assertIsString($secondId);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$secondId.'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T17:30:00Z',
            'idempotency_key' => 'extend-once',
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, BookingMutation::query()->where('idempotency_key', 'extend-once')->count());
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T16:30:00Z',
            'idempotency_key' => 'extend-once',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-06-15T16:30:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T16:30:00Z',
            'idempotency_key' => 'extend-once',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-06-15T16:30:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.extended')->count());
        app(TenantManager::class)->forget();
    }

    public function test_another_workspace_can_reuse_a_confirm_key(): void
    {
        [$alpha, $alphaService] = $this->rental();
        $beta = $this->workspace('other.test');
        $betaLocation = Location::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $betaAsset = Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaLocation->id,
        ]);
        app(TenantManager::class)->set($beta['tenant']);
        $betaService = Service::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaLocation->id,
            'name' => 'Other boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'service_id' => $betaService->id,
            'resource_type' => 'asset',
            'resource_id' => $betaAsset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($alpha);
        $alphaId = $this->confirmed($alphaService, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'shared-confirm');

        $this->actingAsWorkspace($beta);
        $betaHeld = $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings'), [
            'service_id' => $betaService->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'beta-hold',
        ])->assertCreated();
        $betaId = $betaHeld->json('data.id');
        $this->assertIsString($betaId);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$betaId.'/confirm'), [
            'idempotency_key' => 'shared-confirm',
        ])->assertOk()->assertJsonPath('data.id', $betaId);

        $this->assertNotSame($alphaId, $betaId);
        $this->assertSame(2, BookingMutation::withoutGlobalScope(TenantScope::class)->where('idempotency_key', 'shared-confirm')->count());
    }

    public function test_a_long_idempotency_key_is_refused_and_a_header_key_is_kept(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $id = $this->held($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), [
            'idempotency_key' => str_repeat('k', 81),
        ])->assertStatus(422);

        $this->withHeader('Idempotency-Key', 'header-confirm')
            ->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->withHeader('Idempotency-Key', 'header-confirm')
            ->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), [
                'idempotency_key' => 'body-loses',
            ])->assertOk()->assertJsonPath('data.status', 'confirmed');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.confirmed')->count());
        $this->assertSame('header-confirm', BookingMutation::query()->value('idempotency_key'));
        $this->assertSame('confirmed', Booking::query()->findOrFail($id)->status->value);
        app(TenantManager::class)->forget();
    }

    public function test_anonymizing_a_customer_scrubs_the_stored_response_and_leaves_another_workspace(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $this->actingAsWorkspace($workspace);
        $held = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'named-hold',
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'ada@example.com',
        ])->assertCreated();
        $id = $held->json('data.id');
        $customerId = $held->json('data.customer_id');
        $this->assertIsString($id);
        $this->assertIsString($customerId);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), [
            'idempotency_key' => 'named-confirm',
        ])->assertOk();

        $other = $this->workspace('other.test');
        $otherLocation = Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $otherAsset = Asset::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $otherLocation->id,
        ]);
        app(TenantManager::class)->set($other['tenant']);
        $otherService = Service::query()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $otherLocation->id,
            'name' => 'Other',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $other['tenant']->id,
            'service_id' => $otherService->id,
            'resource_type' => 'asset',
            'resource_id' => $otherAsset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($other);
        $otherHeld = $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings'), [
            'service_id' => $otherService->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'bea-hold',
            'customer_name' => 'Bea Paddle',
            'customer_email' => 'bea@example.com',
        ])->assertCreated();
        $otherId = $otherHeld->json('data.id');
        $this->assertIsString($otherId);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$otherId.'/confirm'), [
            'idempotency_key' => 'bea-confirm',
        ])->assertOk();

        Storage::fake('local');
        $this->actingAsWorkspace($workspace);
        $export = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customerId.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.row_counts.booking_mutations', 1);
        $exportId = $export->json('data.id');
        $this->assertIsString($exportId);
        app(TenantManager::class)->set($workspace['tenant']);
        $path = CustomerDataExport::query()->findOrFail($exportId)->path;
        $this->assertIsString($path);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($workspace['tenant']);
        $storedPath = BookingMutation::query()->firstOrFail();
        $this->assertStringContainsString('ada@example.com', json_encode($storedPath->response, JSON_THROW_ON_ERROR));
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customerId.'/deletion'))
            ->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $scrubbed = json_encode(BookingMutation::query()->firstOrFail()->response, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ada@example.com', $scrubbed);
        $this->assertStringNotContainsString('Ada Kayak', $scrubbed);
        app(TenantManager::class)->forget();

        $kept = BookingMutation::withoutGlobalScope(TenantScope::class)
            ->where('idempotency_key', 'bea-confirm')
            ->firstOrFail();
        $this->assertStringContainsString('bea@example.com', json_encode($kept->response, JSON_THROW_ON_ERROR));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $exported = $zip->getFromName('booking_mutations.json');
        $zip->close();
        $this->assertIsString($exported);
        $this->assertStringContainsString('ada@example.com', $exported);
    }

    private function confirmed(Service $service, string $starts, string $ends, ?string $confirmKey = null): string
    {
        $id = $this->held($service, $starts, $ends);
        $body = $confirmKey === null ? [] : ['idempotency_key' => $confirmKey];
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'), $body)->assertOk();

        return $id;
    }

    private function held(Service $service, string $starts, string $ends): string
    {
        $id = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 1,
            'idempotency_key' => 'held-'.md5($starts.$ends.$service->id),
        ])->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
     */
    private function rental(string $host = 'dock.test'): array
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

        return [$workspace, $service, $asset];
    }
}
