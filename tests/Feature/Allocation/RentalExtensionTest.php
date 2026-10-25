<?php

namespace Tests\Feature\Allocation;

use App\Domain\Payments\StripeGateway;
use App\Enums\OperationalStatus;
use App\Livewire\Staff\BookingShow;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class RentalExtensionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_conflict_offers_the_longest_shorter_end_and_another_asset_of_the_same_type(): void
    {
        [$workspace, $service, $asset, $other] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $first = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'base');
        $this->confirmedOnAsset($workspace, $asset, '2026-06-15T17:00:00Z', '2026-06-15T18:00:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T17:30:00Z',
        ])->assertStatus(409)
            ->assertJsonPath('offers.shorter_ends_at', '2026-06-15T17:00:00Z')
            ->assertJsonPath('offers.other_asset_ids.0', $other->id)
            ->assertJsonPath('data.ends_at', '2026-06-15T16:00:00Z');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T17:30:00Z',
            'asset_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-06-15T17:30:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($other->id, Allocation::query()->where('booking_id', $first)->value('asset_id'));
        $this->assertSame(1, Allocation::query()->where('asset_id', $asset->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_second_extension_cannot_take_the_asset_the_first_one_claimed(): void
    {
        [$workspace, $service, $asset, $other] = $this->pair();
        $target = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'resource_category_id' => $asset->resource_category_id,
            'name' => 'Boat C',
        ]);
        $this->actingAsWorkspace($workspace);
        $first = $this->confirmed($service, '2026-06-15T14:00:00Z', '2026-06-15T15:00:00Z', 'one');
        $secondService = $this->serviceOn($workspace, $other, 'Second');
        $second = $this->confirmed($secondService, '2026-06-15T14:00:00Z', '2026-06-15T15:00:00Z', 'two');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/extend'), [
            'ends_at' => '2026-06-15T16:00:00Z',
            'asset_id' => $target->id,
        ])->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/extend'), [
            'ends_at' => '2026-06-15T16:00:00Z',
            'asset_id' => $target->id,
        ])->assertStatus(409)->assertJsonPath('data.ends_at', '2026-06-15T15:00:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Allocation::query()->where('asset_id', $target->id)->count());
        $this->assertSame($other->id, Allocation::query()->where('booking_id', $second)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    public function test_staff_extend_an_active_rental_from_the_booking_page(): void
    {
        [$workspace, $service] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'staff');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(BookingShow::class, ['booking' => $bookingId])
            ->assertSee('Extend rental')
            ->set('extendEnd', '2026-06-15T17:00')
            ->call('extend')
            ->assertSet('problem', null)
            ->assertSet('flash', 'Extended.');

        $this->assertSame('2026-06-15 17:00:00', Booking::query()->findOrFail($bookingId)->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    public function test_staff_see_the_shorter_time_and_can_switch_assets(): void
    {
        [$workspace, $service, $asset, $other] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'staff-conflict');
        $this->confirmedOnAsset($workspace, $asset, '2026-06-15T17:00:00Z', '2026-06-15T18:00:00Z');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(BookingShow::class, ['booking' => $bookingId])
            ->set('extendEnd', '2026-06-15T17:30')
            ->call('extend')
            ->assertSet('problem', 'That time is no longer available.')
            ->assertSee('Extend until Jun 15, 5:00 PM')
            ->assertSee('Use '.$other->name.' instead')
            ->call('acceptAssetExtension', $other->id)
            ->assertSet('flash', 'Extended.');

        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-06-15 17:30:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($other->id, Allocation::query()->where('booking_id', $bookingId)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    public function test_a_customer_can_ask_from_the_booking_page_and_a_conflict_offers_alternatives(): void
    {
        [$workspace, $service, $asset, $other] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'guest', 'ada@example.com');
        $token = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $manage = $this->get((string) $found->headers->get('Location'))->assertOk()->assertSee('Extend rental');
        preg_match('/action="([^"]*extend[^"]*)"/', (string) $manage->getContent(), $action);

        $this->post(html_entity_decode($action[1]), [
            'extra_minutes' => 60,
        ])->assertOk()->assertSee('Ends 2026-06-15T17:00:00');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_extended')->count());
        app(TenantManager::class)->forget();

        $this->confirmedOnAsset($workspace, $asset, '2026-06-15T17:30:00Z', '2026-06-15T18:30:00Z');
        $again = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $page = $this->get((string) $again->headers->get('Location'))->assertOk();
        preg_match('/action="([^"]*extend[^"]*)"/', (string) $page->getContent(), $extend);

        $this->post(html_entity_decode($extend[1]), [
            'extra_minutes' => 60,
        ])->assertStatus(409)
            ->assertSee('That time is no longer available.')
            ->assertSee('Extend until 2026-06-15T17:30:00Z')
            ->assertSee('Use '.$other->name.' instead');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('2026-06-15 17:00:00', Booking::query()->findOrFail($bookingId)->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    public function test_a_card_paid_extension_charges_only_the_increase_through_stripe(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $this->durationPrices($service);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'paid');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.services_cents', 10_000)
            ->assertJsonPath('data.payload.customer_total_cents', 10_300);

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();
        $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => 'evt_extend_paid',
            'type' => 'checkout.session.completed',
            'account' => 'acct_test_123',
            'data' => ['object' => [
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'amount_total' => 10_300,
                'payment_intent' => 'pi_original',
                'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
            ]],
        ], ['Stripe-Signature' => 'test-signature'])->assertOk();

        $fake->sessionId = 'cs_test_extend';
        $fake->sessionUrl = 'https://checkout.stripe.test/pay/cs_test_extend';
        $extended = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/extend'), [
            'ends_at' => '2026-06-15T17:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.ends_at', '2026-06-15T17:00:00Z')
            ->assertJsonPath('checkout.amount_cents', 10_300)
            ->assertJsonPath('checkout.fee_cents', 300)
            ->assertJsonPath('checkout.checkout_url', 'https://checkout.stripe.test/pay/cs_test_extend');

        $this->assertSame(10_300, $fake->sessions[1]['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(300, $fake->sessions[1]['params']['payment_intent_data']['application_fee_amount']);

        $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => 'evt_extend_increase',
            'type' => 'checkout.session.completed',
            'account' => 'acct_test_123',
            'data' => ['object' => [
                'id' => 'cs_test_extend',
                'payment_status' => 'paid',
                'amount_total' => 10_300,
                'payment_intent' => 'pi_extend',
                'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
            ]],
        ], ['Stripe-Signature' => 'test-signature'])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $increase = Payment::query()->where('kind', 'price_increase_stripe_payment')->firstOrFail();
        $this->assertSame(10_300, $increase->amount_cents);
        $this->assertSame(300, $increase->fee_cents);
        $this->assertSame('paid', $increase->status);
        $this->assertSame(600, (int) PlatformFee::query()->sum('amount_cents'));
        app(TenantManager::class)->forget();
        $this->assertNotEmpty($extended->json('checkout.payment_id'));
    }

    public function test_an_offline_extension_is_not_sent_to_stripe(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        [$workspace, $service] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $this->durationPrices($service);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'offline');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/offline-payment'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/extend'), [
            'ends_at' => '2026-06-15T17:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.ends_at', '2026-06-15T17:00:00Z')
            ->assertJsonPath('charge_message', 'The extra time is saved. The higher price is charged by card, with the booking fee on that increase only, once this booking is paid by card.');

        $this->assertSame([], $fake->sessions);
    }

    public function test_a_customer_cannot_take_a_higher_priced_extension_before_the_booking_is_paid(): void
    {
        [$workspace, $service] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $this->durationPrices($service);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'unpaid', 'ada@example.com');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        $token = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $manage = $this->get((string) $found->headers->get('Location'))->assertOk();
        preg_match('/action="([^"]*extend[^"]*)"/', (string) $manage->getContent(), $action);

        $this->post(html_entity_decode($action[1]), [
            'extra_minutes' => 60,
        ])->assertStatus(409)->assertSee('Pay the booking by card before adding time.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('2026-06-15 16:00:00', Booking::query()->findOrFail($bookingId)->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();
    }

    public function test_a_returned_rental_cannot_be_extended(): void
    {
        [$workspace, $service] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'done');
        app(TenantManager::class)->set($workspace['tenant']);
        Booking::query()->findOrFail($bookingId)->update(['operational_status' => OperationalStatus::Returned]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/extend'), [
            'ends_at' => '2026-06-15T17:00:00Z',
        ])->assertStatus(409)->assertJsonPath('message', 'This rental is already finished.');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset, 3: Asset}
     */
    private function pair(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Boats',
            'slug' => 'boats',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat A',
        ]);
        $other = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat B',
        ]);
        $service = $this->serviceOn($workspace, $asset, 'Hourly boat');

        return [$workspace, $service, $asset, $other];
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function serviceOn(array $workspace, Asset $asset, string $name): Service
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $asset->location_id,
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

    private function confirmed(Service $service, string $start, string $end, string $key, ?string $email = null): string
    {
        $body = [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'idempotency_key' => $key,
        ];

        if ($email !== null) {
            $body['customer_name'] = 'Ada';
            $body['customer_email'] = $email;
        }

        $id = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$id.'/confirm'))->assertOk();

        return $id;
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function confirmedOnAsset(array $workspace, Asset $asset, string $start, string $end): string
    {
        $service = $this->serviceOn($workspace, $asset, 'Later '.$start);

        return $this->confirmed($service, $start, $end, 'later-'.$start);
    }

    private function durationPrices(Service $service): void
    {
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'One hour',
            'kind' => 'duration',
            'amount_cents' => 10_000,
            'window' => 'always',
            'duration_minutes' => 60,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Two hours',
            'kind' => 'duration',
            'amount_cents' => 20_000,
            'window' => 'always',
            'duration_minutes' => 120,
        ])->assertCreated();
    }
}
