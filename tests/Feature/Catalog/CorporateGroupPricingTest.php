<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Setup\CorporateAccounts;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CorporateGroupPricingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_can_name_an_account_and_the_quote_records_the_rule(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $account = $this->postJson($this->tenantUrl('dock.test', '/api/v1/corporate-accounts'), [
            'name' => 'Northwind',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Northwind')
            ->assertJsonPath('data.active', true)
            ->json('data.id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Group of 8',
            'kind' => 'per_person',
            'amount_cents' => 0,
            'min_participants' => 8,
            'percent_off' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.percent_off', 10)
            ->assertJsonPath('data.kind', 'base')
            ->assertJsonPath('data.amount_cents', 0);
        $corporate = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Northwind people',
            'kind' => 'per_person',
            'amount_cents' => 1_500,
            'corporate_account_id' => $account,
        ])->assertCreated()
            ->assertJsonPath('data.corporate_account_id', $account)
            ->json('data.id');

        $public = $this->hold($service, 'public', 8, '2026-06-15T14:00:00Z', '2026-06-15T16:00:00Z');
        $named = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T20:00:00Z',
            'participants' => 8,
            'idempotency_key' => 'group-named-'.$service->id,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'corporate_account_id' => $account,
        ])->assertCreated();
        $named->assertJsonPath('data.corporate_account_id', $account);

        $publicPrice = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$public.'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $namedPrice = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$named->json('data.id').'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(9_000, $publicPrice['services_cents']);
        $this->assertSame(10, $publicPrice['group_rules'][0]['percent_off']);
        $this->assertSame(22_000, $namedPrice['services_cents']);
        $this->assertSame($corporate, $namedPrice['group_rules'][0]['price_rule_id']);
        $this->assertSame($account, $namedPrice['group_rules'][0]['corporate_account_id']);
        $this->assertSame('Northwind', $namedPrice['group_rules'][0]['corporate_account_name']);
    }

    public function test_a_percent_without_a_party_or_an_unknown_account_is_refused(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $url = $this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices');

        $this->postJson($url, [
            'name' => 'Missing party',
            'kind' => 'base',
            'amount_cents' => 0,
            'percent_off' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('min_participants');

        $this->postJson($url, [
            'name' => 'Both',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'min_participants' => 4,
            'percent_off' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T14:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'missing-account',
            'customer_name' => 'Ada',
            'corporate_account_id' => '01J00000000000000000000000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('corporate_account_id');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_staff_cannot_add_an_account_and_another_workspace_cannot_see_it(): void
    {
        [$workspace] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $account = $this->postJson($this->tenantUrl('dock.test', '/api/v1/corporate-accounts'), [
            'name' => 'Northwind',
        ])->assertCreated()->json('data.id');

        $staff = $this->workspace('staff.test', 'staff');
        app(TenantManager::class)->set($staff['tenant']);
        $this->actingAsWorkspace($staff);
        $this->postJson($this->tenantUrl('staff.test', '/api/v1/corporate-accounts'), [
            'name' => 'Staff account',
        ])->assertForbidden();
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/corporate-accounts'))->assertOk();
        $this->patchJson($this->tenantUrl('staff.test', '/api/v1/corporate-accounts/'.$account), [
            'name' => 'Taken',
        ])->assertNotFound();

        $this->assertSame(1, CorporateAccount::query()->withoutGlobalScopes()->count());
    }

    public function test_the_service_page_saves_a_percent_off_for_an_account(): void
    {
        [$workspace, $service] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $account = CorporateAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Northwind',
            'active' => true,
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $service->id])
            ->set('priceName', 'Northwind 10')
            ->set('priceMode', 'percent')
            ->set('pricePercent', '10')
            ->set('priceMinParty', '8')
            ->set('priceAccount', $account->id)
            ->call('addPrice')
            ->assertHasNoErrors()
            ->assertSee('10% off')
            ->assertSee('Northwind');

        $rule = PriceRule::query()->firstOrFail();
        $this->assertSame(10, $rule->percent_off);
        $this->assertSame(0, $rule->amount_cents);
        $this->assertSame('base', $rule->kind);
        $this->assertSame($account->id, $rule->corporate_account_id);
    }

    public function test_a_guest_sees_the_public_group_price_and_not_the_corporate_rate(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $account = $this->postJson($this->tenantUrl('dock.test', '/api/v1/corporate-accounts'), [
            'name' => 'Northwind',
        ])->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Group of 8',
            'kind' => 'base',
            'amount_cents' => 0,
            'min_participants' => 8,
            'percent_off' => 10,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Northwind off',
            'kind' => 'base',
            'amount_cents' => 0,
            'min_participants' => 8,
            'percent_off' => 40,
            'corporate_account_id' => $account,
        ])->assertCreated();
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $page = $this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id);

        $this->get($page.'?date=2026-06-15&duration_minutes=60&participants=8')
            ->assertOk()
            ->assertSee('data-group-price="9270"', false)
            ->assertSee('8 or more people, 10% off')
            ->assertSee('Booking fee included')
            ->assertDontSee('Northwind');

        $this->get($page.'?date=2026-06-15&duration_minutes=60&participants=2')
            ->assertOk()
            ->assertDontSee('data-group-price', false);

        $tour = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Harbor tour',
            'kind' => 'tour',
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $tour->id,
            'name' => 'Tour group',
            'kind' => 'base',
            'amount_cents' => 8_000,
            'window' => 'always',
            'min_participants' => 6,
        ]);

        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$tour->id))
            ->assertOk()
            ->assertSee('Show group price');
    }

    public function test_staff_can_save_a_quote_for_a_named_account(): void
    {
        [$workspace, $service] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $account = CorporateAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Northwind',
            'active' => true,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'always',
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Northwind people',
            'kind' => 'per_person',
            'amount_cents' => 1_500,
            'window' => 'always',
            'corporate_account_id' => $account->id,
        ]);
        $start = CarbonImmutable::parse('2026-11-16 15:00:00', 'America/New_York');

        Livewire::test(NewBooking::class, ['serviceId' => $service->id])
            ->set('date', '2026-11-16')
            ->set('participants', 4)
            ->set('durationMinutes', 60)
            ->set('corporateAccountId', $account->id)
            ->set('startsAt', $start->utc()->toIso8601String())
            ->call('saveQuote')
            ->assertHasNoErrors()
            ->assertSet('problem', null);

        $booking = Booking::query()->firstOrFail();
        $this->assertSame($account->id, $booking->corporate_account_id);
        $this->assertSame('Northwind', $booking->customer?->name);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(16_000, $snapshot->payload['services_cents']);
        $this->assertSame($account->id, $snapshot->payload['group_rules'][0]['corporate_account_id']);

        Livewire::test(CorporateAccounts::class)
            ->set('name', 'Harbor Co')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Harbor Co');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function shop(string $host = 'dock.test'): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
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

    private function hold(Service $service, string $suffix, int $participants, string $starts, string $ends): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => $participants,
            'idempotency_key' => 'group-'.$suffix.'-'.$service->id,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
    }
}
