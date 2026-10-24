<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\BookingOperatorFee;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OperatorFeeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_hold_and_quote_put_required_fees_in_the_basis_and_the_snapshot(): void
    {
        Http::fake();
        Mail::fake();
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $this->perPersonPrice($service);
        $this->postJson($this->url('/api/v1/promo-codes'), [
            'code' => 'save10',
            'percent_off' => 10,
        ])->assertCreated();
        $bag = $this->addOn('Dry bag', 200);
        $other = $this->secondService($workspace, 'Kayak');
        $otherLocation = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);

        $this->fee('Admin', 'flat', ['amount_cents' => 300, 'service_id' => $service->id]);
        $this->fee('Cleaning', 'per_person', ['amount_cents' => 500, 'service_id' => $service->id]);
        $this->fee('Fuel', 'percent', ['percent_bps' => 1_000, 'location_id' => $service->location_id]);
        $this->fee('Kayak wash', 'flat', ['amount_cents' => 700, 'service_id' => $other->id]);
        $this->fee('Slip', 'flat', ['amount_cents' => 400, 'location_id' => $otherLocation->id]);
        $this->fee('Closed', 'flat', ['amount_cents' => 900, 'service_id' => $service->id, 'active' => false]);

        $held = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'fees-1', $bag, 2))
            ->assertCreated()
            ->json('data');
        $this->assertIsArray($held);
        $this->assertSame(['Admin', 'Cleaning', 'Fuel'], array_column($held['operator_fees'], 'name'));
        $this->assertSame(300, $held['operator_fees'][0]['total_cents']);
        $this->assertSame(1_000, $held['operator_fees'][1]['total_cents']);
        $this->assertSame(2, $held['operator_fees'][1]['quantity']);
        $this->assertSame(1_000, $held['operator_fees'][2]['total_cents']);

        $priced = $this->postJson($this->url('/api/v1/bookings/'.$held['id'].'/pricing'), [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 1_000,
        ])->assertOk()->json('data.payload');
        $this->assertIsArray($priced);
        $this->assertSame(10_000, $priced['services_cents']);
        $this->assertSame(1_000, $priced['discount_cents']);
        $this->assertSame(400, $priced['add_ons_cents']);
        $this->assertSame(2_300, $priced['mandatory_operator_fees_cents']);
        $this->assertSame(['Admin', 'Cleaning', 'Fuel'], array_column($priced['operator_fees'], 'name'));
        $this->assertSame(11_700, $priced['basis_cents']);
        $this->assertSame(900, $priced['tax_cents']);
        $this->assertSame(351, $priced['fee_cents']);
        $this->assertSame(12_951, $priced['customer_total_cents']);

        $this->postJson($this->url('/api/v1/bookings/'.$held['id'].'/offline-payment'))
            ->assertOk()
            ->assertJsonPath('data.fee_cents', 0);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(12_600, Payment::query()->firstOrFail()->amount_cents);
        app(TenantManager::class)->forget();
    }

    public function test_the_first_quote_refreshes_fees_and_the_second_keeps_them(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cleaning = $this->fee('Cleaning', 'flat', ['amount_cents' => 300, 'service_id' => $service->id]);
        $fuel = $this->fee('Fuel', 'percent', ['percent_bps' => 1_000, 'location_id' => $service->location_id]);

        $bookingId = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'refresh-1'))
            ->assertCreated()
            ->assertJsonPath('data.operator_fees.0.name', 'Cleaning')
            ->assertJsonCount(1, 'data.operator_fees')
            ->json('data.id');
        $this->assertIsString($bookingId);

        $this->perPersonPrice($service, 'base', 10_000);
        $priced = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        $priced->assertJsonPath('data.payload.services_cents', 10_000);
        $priced->assertJsonPath('data.payload.mandatory_operator_fees_cents', 1_300);
        $priced->assertJsonPath('data.payload.operator_fees.1.name', 'Fuel');
        $priced->assertJsonPath('data.payload.operator_fees.1.total_cents', 1_000);
        $priced->assertJsonPath('data.payload.basis_cents', 11_300);
        $priced->assertJsonPath('data.payload.fee_cents', 339);
        $priced->assertJsonPath('data.payload.customer_total_cents', 11_639);

        $this->patchJson($this->url('/api/v1/operator-fees/'.$fuel), ['percent_bps' => 2_000])->assertOk();
        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['active' => false])->assertOk();
        $this->fee('Dock', 'flat', ['amount_cents' => 500, 'location_id' => $service->location_id]);

        $again = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        $this->assertSame($priced->json('data.id'), $again->json('data.id'));
        $again->assertJsonPath('data.payload.mandatory_operator_fees_cents', 1_300);
        $again->assertJsonPath('data.payload.operator_fees.1.total_cents', 1_000);
        $again->assertJsonCount(2, 'data.payload.operator_fees');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(['Cleaning', 'Fuel'], BookingOperatorFee::query()->where('booking_id', $bookingId)->orderBy('name')->pluck('name')->all());
        app(TenantManager::class)->forget();
    }

    public function test_another_workspace_cannot_read_or_apply_a_fee(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $cleaning = $this->fee('Cleaning', 'flat', ['amount_cents' => 2_500, 'service_id' => $service->id]);

        $other = $this->workspace('other.test');
        [, $otherService] = $this->rentalOn($other);
        $this->actingAsWorkspace($other);
        $foreign = $this->postJson($this->tenantUrl('other.test', '/api/v1/operator-fees'), [
            'name' => 'Foreign fuel',
            'pricing' => 'flat',
            'amount_cents' => 800,
            'service_id' => $otherService->id,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($foreign);

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->url('/api/v1/operator-fees/'.$foreign))->assertNotFound();
        $this->patchJson($this->url('/api/v1/operator-fees/'.$foreign), ['amount_cents' => 1])->assertNotFound();
        $this->deleteJson($this->url('/api/v1/operator-fees/'.$foreign))->assertNotFound();
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Borrowed',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'service_id' => $otherService->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['service_id' => 'That service is not in this workspace.']);

        $this->perPersonPrice($service, 'base', 10_000);
        $held = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'own-fee'))
            ->assertCreated()
            ->json('data');
        $this->assertIsArray($held);
        $this->assertSame(['Cleaning'], array_column($held['operator_fees'], 'name'));

        $this->actingAsWorkspace($other);
        $this->getJson($this->tenantUrl('other.test', '/api/v1/operator-fees/'.$cleaning))->assertNotFound();
        $this->getJson($this->tenantUrl('other.test', '/api/v1/operator-fees'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Foreign fuel');
        $this->perPersonPrice($otherService, 'base', 10_000, 'other.test');
        $otherHeld = $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings'), $this->holdBody($otherService, 'other-fee'))
            ->assertCreated()
            ->json('data');
        $this->assertIsArray($otherHeld);
        $this->assertSame(['Foreign fuel'], array_column($otherHeld['operator_fees'], 'name'));
        $this->assertSame(800, $otherHeld['operator_fees'][0]['total_cents']);
    }

    public function test_invalid_fees_and_a_booked_row_are_refused(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $locationId = $service->location_id;

        $created = $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Cleaning',
            'description' => 'After the trip',
            'pricing' => 'flat',
            'amount_cents' => 2_500,
            'service_id' => $service->id,
        ])->assertCreated();
        $created->assertJsonPath('data.pricing', 'flat');
        $created->assertJsonPath('data.amount_cents', 2_500);
        $created->assertJsonPath('data.percent_bps', null);
        $created->assertJsonPath('data.active', true);
        $cleaning = $created->json('data.id');
        $this->assertIsString($cleaning);

        $percent = $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Fuel',
            'pricing' => 'percent',
            'percent_bps' => 1_000,
            'location_id' => $locationId,
        ])->assertCreated();
        $percent->assertJsonPath('data.amount_cents', null);
        $percent->assertJsonPath('data.percent_bps', 1_000);
        $percent->assertJsonPath('data.service_id', null);

        $this->getJson($this->url('/api/v1/operator-fees/'.$cleaning))
            ->assertOk()
            ->assertJsonPath('data.description', 'After the trip');
        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['amount_cents' => 2_000])
            ->assertOk()
            ->assertJsonPath('data.amount_cents', 2_000);
        $this->getJson($this->url('/api/v1/operator-fees'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Cleaning',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'service_id' => $service->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Both',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'service_id' => $service->id,
            'location_id' => $locationId,
        ])->assertStatus(422)->assertJsonValidationErrors(['service_id' => 'Choose a service or a location.']);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Neither',
            'pricing' => 'flat',
            'amount_cents' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors(['service_id' => 'Choose a service or a location.']);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Mixed',
            'pricing' => 'percent',
            'amount_cents' => 100,
            'percent_bps' => 500,
            'service_id' => $service->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount_cents' => 'A percent fee does not take an amount.']);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Rated flat',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'percent_bps' => 500,
            'service_id' => $service->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['percent_bps' => 'Only a percent fee takes a rate.']);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Zero rate',
            'pricing' => 'percent',
            'percent_bps' => 0,
            'service_id' => $service->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['percent_bps']);

        $this->perPersonPrice($service, 'base', 10_000);
        $held = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'booked-fee'))
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($held);

        $this->deleteJson($this->url('/api/v1/operator-fees/'.$cleaning))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This fee is on a booking. Mark it inactive instead.');
        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);
        $this->getJson($this->url('/api/v1/operator-fees'))->assertOk();
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Staff fee',
            'pricing' => 'flat',
            'amount_cents' => 100,
            'service_id' => $service->id,
        ])->assertForbidden();
        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['amount_cents' => 50])->assertForbidden();
        $this->deleteJson($this->url('/api/v1/operator-fees/'.$cleaning))->assertForbidden();
    }

    public function test_checkout_lists_a_required_fee_on_its_own_line(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $this->perPersonPrice($service, 'base', 10_000);
        $this->fee('Cleaning', 'flat', ['amount_cents' => 2_500, 'service_id' => $service->id]);
        $token = $this->postJson($this->url('/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $this->assertIsString($token);

        $held = $this->post($this->url('/book/'.$token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'widget-fee',
        ]);
        $held->assertRedirect();
        $checkout = $this->get((string) $held->headers->get('Location'));
        $checkout->assertOk();
        $html = (string) $checkout->getContent();
        $this->assertMatchesRegularExpression('/Subtotal.*\$100\.00.*Cleaning.*\$25\.00.*Booking fee.*\$3\.75.*\$128\.75/s', $html);
        $this->assertStringContainsString('operator-fee', $html);
        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function fee(string $name, string $pricing, array $scope): string
    {
        $id = $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => $name,
            'pricing' => $pricing,
            ...$scope,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    private function addOn(string $name, int $amountCents): string
    {
        $id = $this->postJson($this->url('/api/v1/add-ons'), [
            'name' => $name,
            'kind' => 'addon',
            'pricing' => 'per_person',
            'amount_cents' => $amountCents,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    private function perPersonPrice(Service $service, string $kind = 'per_person', int $amount = 5_000, string $host = 'dock.test'): void
    {
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => $kind,
            'amount_cents' => $amount,
            'window' => 'weekday',
        ])->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function holdBody(Service $service, string $key, ?string $addOnId = null, int $participants = 2): array
    {
        $body = [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => $participants,
            'idempotency_key' => $key,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ];

        if ($addOnId !== null) {
            $body['add_ons'] = [['id' => $addOnId]];
        }

        return $body;
    }

    private function url(string $path): string
    {
        return $this->tenantUrl('dock.test', $path);
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function secondService(array $workspace, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name,
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

        return $service;
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rentalOn(array $workspace): array
    {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat B',
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

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        return $this->rentalOn($this->workspace('dock.test'));
    }
}
