<?php

namespace Tests\Feature\Widget;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\OperatorFee;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customers\CustomerReceipts;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class LocationTaxCheckoutTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_checkout_confirmation_and_the_receipt_list_the_locations_rates(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/settings'), [
            'pay_at_pickup' => true,
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        OperatorFee::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Cleaning',
            'pricing' => 'flat',
            'amount_cents' => 500,
            'active' => true,
        ]);
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'State sales tax',
            'percent_bps' => 825,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        $city = LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'City tax',
            'percent_bps' => 100,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Fuel tax',
            'percent_bps' => 1_000,
            'applies_to_rentals' => false,
            'applies_to_add_ons' => false,
            'applies_to_fees' => true,
        ]);
        app(TenantManager::class)->forget();

        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('tax-1'));
        $held->assertRedirect();
        $checkoutUrl = (string) $held->headers->get('Location');

        app(TenantManager::class)->set($workspace['tenant']);
        $city->delete();
        app(TenantManager::class)->forget();

        $checkout = $this->get($checkoutUrl)->assertOk();
        $checkout->assertSee('State sales tax')
            ->assertSee('City tax')
            ->assertSee('Fuel tax')
            ->assertSee('$8.25')
            ->assertSee('$1.00')
            ->assertSee('$0.50')
            ->assertSee('Cleaning')
            ->assertSee('$5.00')
            ->assertSee('Booking fee')
            ->assertSee('$3.15')
            ->assertSee('$117.90')
            ->assertSee('Pay $114.75 when you arrive')
            ->assertDontSee('<dt>Taxes</dt>', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $this->bookingId($checkoutUrl))->firstOrFail();
        $this->assertSame(975, $snapshot->payload['tax_cents']);
        $this->assertSame(315, $snapshot->payload['fee_cents']);
        $this->assertSame(11_790, $snapshot->payload['customer_total_cents']);
        $this->assertFalse($snapshot->payload['tax_includes_booking_fee']);
        $this->assertCount(3, $snapshot->payload['tax_rules']);
        app(TenantManager::class)->forget();

        $confirmed = $this->post($this->formAction((string) $checkout->getContent(), 'Pay at pickup'))->assertRedirect();
        $this->get((string) $confirmed->headers->get('Location'))
            ->assertOk()
            ->assertSee('Your booking is confirmed.')
            ->assertSee('State sales tax')
            ->assertSee('$8.25')
            ->assertSee('Fuel tax')
            ->assertSee('$0.50');

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($this->bookingId($checkoutUrl));
        $receipt = app(CustomerReceipts::class)->forBooking($booking);
        app(TenantManager::class)->forget();

        $this->assertSame(975, $receipt['tax_cents']);
        $this->assertSame('State sales tax', $receipt['tax_lines'][0]['name']);
        $this->assertSame(825, $receipt['tax_lines'][0]['cents']);
        $this->assertSame('Fuel tax', $receipt['tax_lines'][2]['name']);
        $this->assertSame(50, $receipt['tax_lines'][2]['cents']);
        $this->assertIsString($receipt['html']);
        $this->assertStringContainsString('State sales tax', $receipt['html']);
        $this->assertStringContainsString('$8.25', $receipt['html']);
        $this->assertStringContainsString('Fuel tax', $receipt['html']);
        $this->assertStringContainsString('Booking fee', $receipt['html']);
        $this->assertStringContainsString('$3.15', $receipt['html']);
    }

    public function test_a_location_with_no_rates_still_charges_no_tax(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'name' => 'Other dock tax',
            'percent_bps' => 825,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        app(TenantManager::class)->forget();

        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('tax-none'));
        $held->assertRedirect();

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee('Taxes')
            ->assertSee('$0.00')
            ->assertSee('$3.00')
            ->assertSee('$103.00')
            ->assertDontSee('Other dock tax');
    }

    public function test_checkout_taxes_the_booking_fee_only_when_that_location_says_so(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
            'booking_fee_taxable' => true,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        Location::query()->whereKey($service->location_id)->update(['booking_fee_taxable' => true]);
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'State sales tax',
            'percent_bps' => 1_000,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'name' => 'Other dock tax',
            'percent_bps' => 2_000,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        app(TenantManager::class)->forget();

        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('tax-fee'));
        $held->assertRedirect();
        $checkoutUrl = (string) $held->headers->get('Location');

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee('State sales tax')
            ->assertSee('$10.30')
            ->assertSee('Booking fee')
            ->assertSee('$3.00')
            ->assertSee('$113.30')
            ->assertDontSee('Other dock tax');

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $this->bookingId($checkoutUrl))->firstOrFail();
        $this->assertSame(10_000, $snapshot->payload['basis_cents']);
        $this->assertSame(300, $snapshot->payload['fee_cents']);
        $this->assertSame(300, $snapshot->payload['application_fee_cents']);
        $this->assertSame(1_030, $snapshot->payload['tax_cents']);
        $this->assertSame(11_330, $snapshot->payload['customer_total_cents']);
        $this->assertTrue($snapshot->payload['tax_includes_booking_fee']);
        $this->assertSame(10_300, $snapshot->payload['tax_lines'][0]['basis_cents']);

        Location::query()->whereKey($service->location_id)->update(['booking_fee_taxable' => false]);
        app(TenantManager::class)->forget();

        $later = $this->party('tax-fee-off');
        $later['start'] = '16:00';
        $again = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $later);
        $again->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $plain = PricingSnapshot::query()->where('booking_id', $this->bookingId((string) $again->headers->get('Location')))->firstOrFail();
        app(TenantManager::class)->forget();

        $this->assertSame(1_000, $plain->payload['tax_cents']);
        $this->assertSame(300, $plain->payload['fee_cents']);
        $this->assertSame(11_300, $plain->payload['customer_total_cents']);
        $this->assertFalse($plain->payload['tax_includes_booking_fee']);
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function token(array $workspace): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(string $host): array
    {
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        return [$workspace, $service];
    }

    private function formAction(string $html, string $button): string
    {
        $pattern = '/<form method="post" action="([^"]+)"[^>]*>\s*<button[^>]*>'.preg_quote($button, '/').'/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing the '.$button.' form.');

        return html_entity_decode($matches[1]);
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);

        return $matches[1];
    }

    /**
     * @return array<string, string|int>
     */
    private function party(string $key): array
    {
        return [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
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

        return [$workspace, $service];
    }
}
