<?php

namespace Tests\Feature\Operations;

use App\Enums\ResourceStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\IntegrationOutbox;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReturnConditionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_return_stores_each_asset_condition_and_flags_only_damaged_gear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental('dock.test', 'staff', ['Boat A', 'Boat B']);
        $damaged = $assets[0];
        $ok = $assets[1];
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->checkedOut($service, '13:00', '15:00', 'cond-open');
        $futureId = $this->confirmed($service, '18:00', '19:00', 'cond-later');

        Carbon::setTestNow(Carbon::parse('2026-06-16 13:30:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $damaged->id, 'condition' => 'damaged', 'notes' => 'Hull crack', 'recorded_by' => $beta['user']->id],
                ['asset_id' => $ok->id, 'condition' => 'ok', 'notes' => ''],
            ],
        ])->assertOk()
            ->assertJsonPath('data.id', $bookingId)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonFragment([
                'asset_id' => $damaged->id,
                'condition' => 'damaged',
                'notes' => 'Hull crack',
                'recorded_by' => $workspace['user']->id,
            ])
            ->assertJsonFragment([
                'asset_id' => $ok->id,
                'condition' => 'ok',
                'notes' => null,
                'recorded_by' => $workspace['user']->id,
            ]);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $damaged->id, 'condition' => 'ok'],
                ['asset_id' => $ok->id, 'condition' => 'ok'],
            ],
        ])->assertStatus(409);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['asset_id' => $damaged->id, 'condition' => 'damaged', 'notes' => 'Hull crack'])
            ->assertJsonFragment(['asset_id' => $ok->id, 'condition' => 'ok', 'notes' => null]);

        app(TenantManager::class)->set($workspace['tenant']);
        $future = Booking::query()->findOrFail($futureId);
        $this->assertSame('confirmed', $future->status->value);
        $this->assertSame('Asset is maintenance.', $future->attention_reason);
        $returned = Booking::query()->findOrFail($bookingId);
        $this->assertNull($returned->attention_reason);
        $this->assertSame(ResourceStatus::Maintenance, $damaged->refresh()->status);
        $this->assertSame(ResourceStatus::Active, $ok->refresh()->status);
        $this->assertSame(2, RentalReturn::query()->where('booking_id', $bookingId)->count());
        $event = IntegrationOutbox::query()->where('kind', 'booking.returned')->firstOrFail();
        $this->assertSame($workspace['tenant']->id, $event->payload['tenant_id']);
        $this->assertSame($bookingId, $event->payload['booking_id']);
        $conditions = $event->payload['conditions'];
        $this->assertIsArray($conditions);
        usort($conditions, function (array $left, array $right): int {
            return $left['asset_id'] <=> $right['asset_id'];
        });
        $expected = [
            ['asset_id' => $damaged->id, 'condition' => 'damaged', 'notes' => 'Hull crack', 'recorded_by' => $workspace['user']->id],
            ['asset_id' => $ok->id, 'condition' => 'ok', 'notes' => null, 'recorded_by' => $workspace['user']->id],
        ];
        usort($expected, function (array $left, array $right): int {
            return $left['asset_id'] <=> $right['asset_id'];
        });
        $this->assertEquals($expected, $conditions);
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, RentalReturn::query()->count());
        $this->assertSame(0, IntegrationOutbox::query()->where('kind', 'booking.returned')->count());
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/bookings/'.$bookingId.'/returns'))->assertNotFound();
        $this->postJson($this->tenantUrl('beta.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $damaged->id, 'condition' => 'ok'],
            ],
        ])->assertNotFound();
    }

    public function test_a_late_damaged_return_stores_the_fee_and_does_not_charge_stripe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['late_fee_per_minute_cents' => 50]);
        app(TenantManager::class)->forget();
        $bookingId = $this->checkedOut($service, '18:00', '19:00', 'cond-late');

        Carbon::setTestNow(Carbon::parse('2026-06-16 19:20:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'damaged', 'notes' => 'Broken latch'],
            ],
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1_000, RentalCheckout::query()->where('booking_id', $bookingId)->firstOrFail()->late_fee_cents);
        $this->assertSame(ResourceStatus::Maintenance, $assets[0]->refresh()->status);
        app(TenantManager::class)->forget();
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_missing_gear_is_recorded_and_left_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->checkedOut($service, '13:00', '15:00', 'cond-missing');
        $futureId = $this->confirmed($service, '18:00', '19:00', 'cond-missing-later');

        Carbon::setTestNow(Carbon::parse('2026-06-16 13:30:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'missing', 'notes' => 'Not on the rack'],
            ],
        ])->assertOk()->assertJsonPath('data.returns.0.condition', 'missing');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Active, $assets[0]->refresh()->status);
        $this->assertNull(Booking::query()->findOrFail($futureId)->attention_reason);
        $this->assertSame('missing', RentalReturn::query()->where('booking_id', $bookingId)->firstOrFail()->condition->value);
        app(TenantManager::class)->forget();
    }

    public function test_conditions_must_match_the_assets_on_this_rental(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental();
        $beta = $this->workspace('other.test');
        $foreign = Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'timezone' => 'UTC'])->id,
            'name' => 'Other boat',
        ]);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->checkedOut($service, '13:00', '15:00', 'cond-invalid');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'broken'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'conditions.0.condition' => 'Condition must be ok, damaged, or missing.',
        ]);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['condition' => 'ok'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['conditions.0.asset_id']);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'ok'],
                ['asset_id' => $assets[0]->id, 'condition' => 'damaged'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'conditions' => 'Each asset can be recorded once.',
        ]);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'conditions' => 'Record a condition for every asset on this rental.',
        ]);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $foreign->id, 'condition' => 'damaged', 'notes' => 'Stolen'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'conditions' => 'Record a condition for every asset on this rental.',
        ]);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'ok', 'notes' => str_repeat('a', 501)],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['conditions.0.notes']);
        $this->assertStillOut($workspace, $bookingId);

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(ResourceStatus::Active, $foreign->refresh()->status);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'ok', 'notes' => "O'Brien said \"fine\""],
            ],
        ])->assertOk()->assertJsonPath('data.returns.0.notes', "O'Brien said \"fine\"");

        $plainId = $this->checkedOut($service, '16:00', '17:00', 'cond-plain');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$plainId.'/return'))->assertOk()
            ->assertJsonPath('data.returns', []);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, RentalReturn::query()->where('booking_id', $plainId)->count());
        $events = IntegrationOutbox::query()->where('kind', 'booking.returned')->get();
        $byBooking = [];

        foreach ($events as $event) {
            $bookingKey = $event->payload['booking_id'] ?? null;

            if (is_string($bookingKey)) {
                $byBooking[$bookingKey] = $event->payload['conditions'] ?? null;
            }
        }

        $this->assertSame([], $byBooking[$plainId]);
        $this->assertSame('ok', $byBooking[$bookingId][0]['condition']);
        app(TenantManager::class)->forget();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$plainId.'/returns'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_recording_a_return_requires_booking_access_in_this_workspace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental('dock.test', 'owner');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/01HZZZZZZZZZZZZZZZZZZZZZZZ/returns'), [
            'conditions' => [],
        ])->assertUnauthorized();
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/01HZZZZZZZZZZZZZZZZZZZZZZZ/returns'))
            ->assertUnauthorized();

        $this->actingAsWorkspace($workspace);
        $bookingId = $this->checkedOut($service, '13:00', '15:00', 'cond-auth');
        $staff = User::factory()->create();
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.manage')->firstOrFail()->id);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);

        $this->actingAsWorkspace(['user' => $staff]);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'))
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/returns'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'damaged'],
            ],
        ])->assertForbidden();
        $this->assertStillOut($workspace, $bookingId);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Active, $assets[0]->refresh()->status);
        app(TenantManager::class)->forget();
    }

    private function assertStillOut(array $workspace, string $bookingId): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(RentalCheckout::query()->where('booking_id', $bookingId)->firstOrFail()->returned_at);
        $this->assertSame(0, RentalReturn::query()->where('booking_id', $bookingId)->count());
        app(TenantManager::class)->forget();
    }

    private function checkedOut(Service $service, string $start, string $end, string $key): string
    {
        $bookingId = $this->confirmed($service, $start, $end, $key);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout'))->assertOk();

        return $bookingId;
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
     * @param  list<string>  $names
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: list<Asset>}
     */
    private function rental(string $host = 'dock.test', string $role = 'staff', array $names = ['Boat A']): array
    {
        $workspace = $this->workspace($host, $role);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $assets = [];
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);

        foreach ($names as $name) {
            $asset = Asset::factory()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'name' => $name,
            ]);
            ResourceRequirement::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'service_id' => $service->id,
                'resource_type' => 'asset',
                'resource_id' => $asset->id,
                'quantity' => 1,
            ]);
            $assets[] = $asset;
        }

        app(TenantManager::class)->forget();

        return [$workspace, $service, $assets];
    }
}
