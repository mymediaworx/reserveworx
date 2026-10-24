<?php

namespace Tests\Feature\Booking;

use App\Models\Asset;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Participant;
use App\Models\PlatformAdministrator;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ParticipantTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_names_the_party_and_links_a_waiver_to_the_minor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 2, 'party-1');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => '   ',
            'is_minor' => false,
        ])->assertStatus(422);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Sam',
            'is_minor' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('guardian_name');

        $adult = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => '  Ada Quinn  ',
            'is_minor' => false,
            'guardian_name' => 'Should be ignored',
        ])->assertCreated()->assertJsonPath('data.name', 'Ada Quinn')->assertJsonPath('data.guardian_name', null)->json('data');

        $minor = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Sam Quinn',
            'is_minor' => true,
            'guardian_name' => '  Ada Quinn  ',
        ])->assertCreated()->assertJsonPath('data.guardian_name', 'Ada Quinn')->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Extra',
            'is_minor' => false,
        ])->assertStatus(422);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $adult['id'])
            ->assertJsonPath('data.1.id', $minor['id']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant.added',
            'tenant_id' => $workspace['tenant']->id,
            'subject_id' => $minor['id'],
        ]);

        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data');

        $signed = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $waiver['id'],
            'participant_id' => $minor['id'],
            'participant_name' => 'Someone else',
            'is_minor' => false,
        ])->assertCreated()
            ->assertJsonPath('data.participant_id', $minor['id'])
            ->assertJsonPath('data.participant_name', 'Sam Quinn')
            ->assertJsonPath('data.guardian_name', 'Ada Quinn')
            ->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $waiver['id'],
            'participant_id' => $minor['id'],
        ])->assertOk()->assertJsonPath('data.id', $signed['id']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, WaiverSignature::query()->count());
        $signature = WaiverSignature::query()->firstOrFail();
        $this->assertSame($minor['id'], $signature->participant_id);
        $this->assertTrue($signature->is_minor);
        app(TenantManager::class)->forget();

        $this->deleteJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants/'.$minor['id']))
            ->assertStatus(409);

        $this->deleteJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants/'.$adult['id']))
            ->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $minor['id']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant.removed',
            'subject_id' => $adult['id'],
        ]);
    }

    public function test_an_idempotency_key_does_not_create_a_second_person(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 2, 'party-2');
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants');

        $first = $this->postJson($url, [
            'name' => 'Ada',
            'is_minor' => false,
        ], ['Idempotency-Key' => 'person-1'])->assertCreated()->json('data.id');

        $this->postJson($url, [
            'name' => 'Ada',
            'is_minor' => false,
        ], ['Idempotency-Key' => 'person-1'])->assertOk()->assertJsonPath('data.id', $first);

        $this->postJson($url, [
            'name' => 'Sam',
            'is_minor' => false,
        ], ['Idempotency-Key' => 'person-1'])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Participant::query()->where('booking_id', $bookingId)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_participant_from_another_booking_or_workspace_is_not_found(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $first = $this->hold($service, 1, 'party-3');
        $second = $this->hold($service, 1, 'party-4', '17:00', '18:00');
        $person = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/participants'), [
            'name' => 'Ada',
            'is_minor' => false,
        ])->assertCreated()->json('data.id');

        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/waivers'), [
            'waiver_version_id' => $waiver,
            'participant_id' => $person,
        ])->assertStatus(422);

        $this->deleteJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/participants/'.$person))
            ->assertNotFound();

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/participants'))
            ->assertForbidden();
    }

    public function test_a_workspace_export_includes_the_named_participant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        Storage::fake('local');
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 1, 'party-5');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'River Quinn',
            'is_minor' => false,
        ])->assertCreated();

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id.'/exports')
            ->assertCreated();
        $id = $created->json('data.id');
        $this->assertIsString($id);
        $json = Storage::disk('local')->get('tenant-exports/'.$workspace['tenant']->id.'/'.$id.'.json');
        $this->assertIsString($json);
        $this->assertStringContainsString('River Quinn', $json);
    }

    private function hold(Service $service, int $participants, string $key, string $start = '15:00', string $end = '16:00'): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T'.$start.':00Z',
            'ends_at' => '2026-06-16T'.$end.':00Z',
            'participants' => $participants,
            'idempotency_key' => $key,
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
