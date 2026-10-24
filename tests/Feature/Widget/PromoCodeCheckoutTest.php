<?php

namespace Tests\Feature\Widget;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PromoCodeCheckoutTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_percent_code_recalculates_the_booking_fee_and_counts_only_when_confirmed(): void
    {
        [$workspace, $service] = $this->pricedRental();
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/settings'), [
            'pay_at_pickup' => true,
        ])->assertOk();
        $promo = $this->promo($workspace['tenant'], 'SAVE10', ['percent_off' => 10, 'usage_limit' => 1]);
        $token = $this->token($workspace);

        $firstUrl = $this->hold($token, $service, 'promo-a', '13:00');
        $checkout = $this->get($firstUrl)->assertOk();
        $checkout->assertSee('Promo code')
            ->assertSee('$100.00')
            ->assertSee('$3.00')
            ->assertSee('$103.00')
            ->assertDontSee('Discount');

        $applied = $this->post($this->formAction((string) $checkout->getContent(), 'Apply code'), [
            'promo_code' => 'save10',
        ]);
        $applied->assertRedirect();
        $review = $this->get((string) $applied->headers->get('Location'))->assertOk();
        $review->assertSee('Discount (SAVE10)')
            ->assertSee('-$10.00')
            ->assertSee('$2.70')
            ->assertSee('$92.70')
            ->assertSee('Pay $90.00 when you arrive')
            ->assertDontSee('$103.00');

        app(TenantManager::class)->set($workspace['tenant']);
        $firstId = $this->bookingId($firstUrl);
        $snapshot = PricingSnapshot::query()->where('booking_id', $firstId)->firstOrFail();
        $this->assertSame(10_000, $snapshot->payload['services_cents']);
        $this->assertSame(1_000, $snapshot->payload['discount_cents']);
        $this->assertSame(9_000, $snapshot->payload['basis_cents']);
        $this->assertSame(270, $snapshot->payload['fee_cents']);
        $this->assertSame(270, $snapshot->payload['customer_fee_line_cents']);
        $this->assertSame(9_270, $snapshot->payload['customer_total_cents']);
        $this->assertSame('SAVE10', $snapshot->payload['promo_code']);
        $this->assertSame(0, $promo->fresh()?->redeemed_count);
        app(TenantManager::class)->forget();

        $secondUrl = $this->hold($token, $service, 'promo-b', '15:00');
        $secondCheckout = $this->get($secondUrl)->assertOk();
        $this->post($this->formAction((string) $secondCheckout->getContent(), 'Apply code'), [
            'promo_code' => 'SAVE10',
        ])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, $promo->fresh()?->redeemed_count);
        app(TenantManager::class)->forget();

        $this->post($this->formAction((string) $review->getContent(), 'Pay at pickup'))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('confirmed', Booking::query()->findOrFail($firstId)->status->value);
        $this->assertSame(1, $promo->fresh()?->redeemed_count);
        $kept = PricingSnapshot::query()->where('booking_id', $firstId)->firstOrFail();
        $this->assertSame(270, $kept->payload['fee_cents']);
        app(TenantManager::class)->forget();

        $thirdUrl = $this->hold($token, $service, 'promo-c', '17:00');
        $third = $this->get($thirdUrl)->assertOk();
        $this->post($this->formAction((string) $third->getContent(), 'Apply code'), [
            'promo_code' => 'SAVE10',
        ])->assertStatus(422)
            ->assertSee('That promo code has been used up.')
            ->assertSee('$3.00')
            ->assertSee('$103.00')
            ->assertDontSee('Discount');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, $promo->fresh()?->redeemed_count);
        $refused = PricingSnapshot::query()->where('booking_id', $this->bookingId($thirdUrl))->firstOrFail();
        $this->assertNull($refused->payload['promo_code']);
        $this->assertSame(300, $refused->payload['fee_cents']);
        $this->assertSame(10_300, $refused->payload['customer_total_cents']);
        app(TenantManager::class)->forget();
    }

    public function test_a_fixed_amount_reduces_the_fee_basis(): void
    {
        [$workspace, $service] = $this->pricedRental();
        $this->promo($workspace['tenant'], 'TAKE20', ['amount_off_cents' => 2_000]);
        $token = $this->token($workspace);
        $url = $this->hold($token, $service, 'promo-fixed', '13:00');
        $checkout = $this->get($url)->assertOk();

        $applied = $this->post($this->formAction((string) $checkout->getContent(), 'Apply code'), [
            'promo_code' => 'take20',
        ])->assertRedirect();

        $this->get((string) $applied->headers->get('Location'))
            ->assertOk()
            ->assertSee('Discount (TAKE20)')
            ->assertSee('-$20.00')
            ->assertSee('$2.40')
            ->assertSee('$82.40');

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $this->bookingId($url))->firstOrFail();
        $this->assertSame(2_000, $snapshot->payload['discount_cents']);
        $this->assertSame(8_000, $snapshot->payload['basis_cents']);
        $this->assertSame(240, $snapshot->payload['fee_cents']);
        $this->assertSame(8_240, $snapshot->payload['customer_total_cents']);
        app(TenantManager::class)->forget();
    }

    public function test_invalid_expired_and_not_yet_valid_codes_leave_the_price_unchanged(): void
    {
        [$workspace, $service] = $this->pricedRental();
        $this->promo($workspace['tenant'], 'OLD', ['percent_off' => 25, 'ends_on' => '2026-06-14']);
        $this->promo($workspace['tenant'], 'LATER', ['percent_off' => 25, 'starts_on' => '2026-06-16']);
        $this->promo($workspace['tenant'], 'OFF', ['percent_off' => 25, 'active' => false]);
        $token = $this->token($workspace);
        $url = $this->hold($token, $service, 'promo-bad', '13:00');
        $checkout = $this->get($url)->assertOk();
        $action = $this->formAction((string) $checkout->getContent(), 'Apply code');

        $this->post($action, ['promo_code' => ''])
            ->assertStatus(422)
            ->assertSee('Enter a promo code.');

        foreach ([
            'NOPE' => 'That promo code is not valid.',
            'OLD' => 'That promo code has expired.',
            'LATER' => 'That promo code is not valid yet.',
            'OFF' => 'That promo code is not valid.',
        ] as $code => $message) {
            $this->post($action, ['promo_code' => $code])
                ->assertStatus(422)
                ->assertSee($message)
                ->assertSee('$3.00')
                ->assertSee('$103.00')
                ->assertDontSee('Discount');
        }

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $this->bookingId($url))->firstOrFail();
        $this->assertNull($snapshot->payload['promo_code']);
        $this->assertSame(0, $snapshot->payload['discount_cents']);
        $this->assertSame(300, $snapshot->payload['fee_cents']);
        $this->assertSame(0, PromoCode::query()->sum('redeemed_count'));
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function promo(Tenant $tenant, string $code, array $attributes): PromoCode
    {
        app(TenantManager::class)->set($tenant);
        $promo = PromoCode::query()->create($attributes + [
            'tenant_id' => $tenant->id,
            'code' => $code,
            'active' => true,
        ]);
        app(TenantManager::class)->forget();

        return $promo;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function token(array $workspace): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    private function hold(string $token, Service $service, string $key, string $start): string
    {
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => $start,
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ]);
        $held->assertRedirect();

        return (string) $held->headers->get('Location');
    }

    private function formAction(string $html, string $button): string
    {
        preg_match_all('/<form method="post" action="([^"]+)"[^>]*>(.*?)<\/form>/s', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (str_contains($match[2], '>'.$button.'<') || str_contains($match[2], '>'.$button)) {
                return html_entity_decode($match[1]);
            }
        }

        $this->fail('Missing the '.$button.' form.');
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);

        return $matches[1];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(): array
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
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();

        return [$workspace, $service];
    }
}
