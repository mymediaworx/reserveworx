<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\BookingAddOn;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class AddOnTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_hold_prices_add_ons_into_the_fee_basis_and_the_snapshot(): void
    {
        Http::fake();
        Mail::fake();
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $bag = $this->catalog('Dry bag', 'addon', 'per_person', 200);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);

        $this->getJson($this->url('/api/v1/add-ons'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson($this->url('/api/v1/add-ons/'.$paddle))
            ->assertOk()
            ->assertJsonPath('data.kind', 'equipment')
            ->assertJsonPath('data.max_quantity', 4);

        $this->postJson($this->url('/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        $body = $this->holdBody($service, 'addons-1', [
            ['id' => $cooler],
            ['id' => $bag],
            ['id' => $paddle, 'quantity' => 2],
        ]);
        $held = $this->postJson($this->url('/api/v1/bookings'), $body)
            ->assertCreated()
            ->json('data');
        $this->assertIsArray($held);
        $this->assertSame(1_500, $held['add_ons'][0]['total_cents']);
        $this->assertSame(400, $held['add_ons'][1]['total_cents']);
        $this->assertSame(1_000, $held['add_ons'][2]['total_cents']);

        $this->postJson($this->url('/api/v1/bookings'), $body)
            ->assertCreated()
            ->assertJsonPath('data.id', $held['id']);

        $this->postJson($this->url('/api/v1/bookings'), [
            ...$body,
            'add_ons' => [['id' => $cooler]],
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(3, BookingAddOn::query()->count());
        app(TenantManager::class)->forget();

        $priced = $this->postJson($this->url('/api/v1/bookings/'.$held['id'].'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertIsArray($priced);
        $this->assertSame(10_000, $priced['services_cents']);
        $this->assertSame(2_900, $priced['add_ons_cents']);
        $this->assertCount(3, $priced['add_ons']);
        $this->assertSame(12_900, $priced['basis_cents']);
        $this->assertSame(0, $priced['tax_cents']);
        $this->assertSame(387, $priced['fee_cents']);
        $this->assertSame(13_287, $priced['customer_total_cents']);
        $this->assertSame(387, $priced['application_fee_cents']);

        $this->postJson($this->url('/api/v1/bookings/'.$held['id'].'/offline-payment'))
            ->assertOk()
            ->assertJsonPath('data.fee_cents', 0)
            ->assertJsonPath('data.payment_status', 'offline_paid');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(12_900, Payment::query()->firstOrFail()->amount_cents);
        $this->assertSame(0, Payment::query()->firstOrFail()->fee_cents);
        app(TenantManager::class)->forget();
    }

    public function test_a_quote_can_choose_add_ons_until_the_snapshot_exists(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 4);
        $this->postJson($this->url('/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        $bookingId = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'quote-1', [
            ['id' => $cooler],
        ]))->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);

        $priced = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'), [
            'add_ons' => [['id' => $paddle, 'quantity' => 2]],
        ])->assertOk();
        $priced->assertJsonPath('data.payload.add_ons_cents', 1_000);
        $priced->assertJsonPath('data.payload.basis_cents', 11_000);
        $priced->assertJsonPath('data.payload.fee_cents', 330);
        $priced->assertJsonPath('data.payload.add_ons.0.name', 'Paddle');
        $priced->assertJsonPath('data.payload.customer_total_cents', 11_330);

        $again = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'), [
            'add_ons' => [['id' => $cooler]],
        ])->assertOk();
        $this->assertSame($priced->json('data.id'), $again->json('data.id'));
        $again->assertJsonPath('data.payload.add_ons_cents', 1_000);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, BookingAddOn::query()->where('booking_id', $bookingId)->count());
        $this->assertSame('Paddle', BookingAddOn::query()->where('booking_id', $bookingId)->firstOrFail()->name);
        app(TenantManager::class)->forget();
    }

    public function test_another_workspace_cannot_read_or_choose_an_add_on(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $foreign = $this->postJson($this->tenantUrl('other.test', '/api/v1/add-ons'), [
            'name' => 'Foreign cooler',
            'kind' => 'addon',
            'pricing' => 'flat',
            'amount_cents' => 900,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($foreign);

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->url('/api/v1/add-ons/'.$foreign))->assertNotFound();
        $this->patchJson($this->url('/api/v1/add-ons/'.$foreign), ['amount_cents' => 1])->assertNotFound();
        $this->deleteJson($this->url('/api/v1/add-ons/'.$foreign))->assertNotFound();
        $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'foreign', [
            ['id' => $foreign],
        ]))->assertStatus(422)->assertJsonValidationErrors(['add_ons.0.id']);

        $this->actingAsWorkspace($other);
        $this->getJson($this->tenantUrl('other.test', '/api/v1/add-ons/'.$cooler))->assertNotFound();
        $this->getJson($this->tenantUrl('other.test', '/api/v1/add-ons'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Foreign cooler');
    }

    public function test_invalid_choices_and_a_booked_catalog_row_are_refused(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cooler = $this->catalog('Cooler', 'addon', 'flat', 1_500);
        $paddle = $this->catalog('Paddle', 'equipment', 'each', 500, 2);
        $this->postJson($this->url('/api/v1/add-ons'), [
            'name' => 'Cooler',
            'kind' => 'addon',
            'pricing' => 'flat',
            'amount_cents' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson($this->url('/api/v1/add-ons'), [
            'name' => 'Life jacket',
            'kind' => 'equipment',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'max_quantity' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_quantity']);

        $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'rules', [
            ['id' => $cooler, 'quantity' => 2],
        ]))->assertStatus(422)->assertJsonValidationErrors(['add_ons.0.quantity']);

        $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'rules-2', [
            ['id' => $paddle, 'quantity' => 3],
            ['id' => $paddle, 'quantity' => 1],
        ]))->assertStatus(422);

        $held = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'rules-3', [
            ['id' => $paddle, 'quantity' => 2],
        ]))->assertCreated()->json('data.id');
        $this->assertIsString($held);

        $this->deleteJson($this->url('/api/v1/add-ons/'.$paddle))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This add-on is on a booking. Mark it inactive instead.');

        $this->patchJson($this->url('/api/v1/add-ons/'.$paddle), ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'rules-4', [
            ['id' => $paddle, 'quantity' => 1],
        ]))->assertStatus(422)->assertJsonValidationErrors(['add_ons.0.id']);

        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);
        $this->getJson($this->url('/api/v1/add-ons'))->assertOk();
        $this->postJson($this->url('/api/v1/add-ons'), [
            'name' => 'Staff cooler',
            'kind' => 'addon',
            'pricing' => 'flat',
            'amount_cents' => 100,
        ])->assertForbidden();
        $this->patchJson($this->url('/api/v1/add-ons/'.$cooler), ['amount_cents' => 50])->assertForbidden();
        $this->deleteJson($this->url('/api/v1/add-ons/'.$cooler))->assertForbidden();
    }

    /**
     * @param  list<array{id: string, quantity?: int}>  $addOns
     * @return array<string, mixed>
     */
    private function holdBody(Service $service, string $key, array $addOns): array
    {
        return [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 2,
            'idempotency_key' => $key,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'add_ons' => $addOns,
        ];
    }

    private function catalog(string $name, string $kind, string $pricing, int $amountCents, ?int $maxQuantity = null): string
    {
        $body = [
            'name' => $name,
            'kind' => $kind,
            'pricing' => $pricing,
            'amount_cents' => $amountCents,
        ];

        if ($maxQuantity !== null) {
            $body['max_quantity'] = $maxQuantity;
        }

        $id = $this->postJson($this->url('/api/v1/add-ons'), $body)->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    private function url(string $path): string
    {
        return $this->tenantUrl('dock.test', $path);
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
