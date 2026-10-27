<?php

namespace Tests\Feature\Widget;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicWaiverCheckoutTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const LIABILITY = 'I accept the risks of this activity, including <script>alert(1)</script>.';

    private const PHOTO = 'Photos of the visit may be used by the shop.';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    public function test_payment_waits_until_the_current_waiver_is_signed(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        [$workspace, $service] = $this->pricedRental('dock.test', true);
        $this->publish($workspace['tenant'], 'Liability waiver', self::LIABILITY);
        $this->publish($workspace['tenant'], 'Photo release', self::PHOTO);
        $total = null;

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$this->token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'waiver-hold',
        ])->assertRedirect();
        $checkoutUrl = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($checkoutUrl);
        $total = $this->customerTotal($workspace['tenant'], $bookingId);

        $checkout = $this->get($checkoutUrl)->assertOk();
        $checkout->assertSee('Sign your waiver')
            ->assertSee('Liability waiver')
            ->assertSee('Photo release')
            ->assertSee(self::PHOTO)
            ->assertSee('<button type="button" disabled aria-disabled="true" aria-describedby="pay-locked-note">Pay with card</button>', false)
            ->assertSee('Sign the waiver above to continue to payment.')
            ->assertDontSee('<script>alert(1)</script>', false);
        $checkout->assertSee('<script>alert(1)</script>');

        $this->post($this->signed('widget.pay', $workspace['tenant'], $bookingId))
            ->assertStatus(409)
            ->assertSee('Sign the waiver before you pay.')
            ->assertDontSee('Pay with card');
        $this->post($this->signed('widget.pickup', $workspace['tenant'], $bookingId))
            ->assertStatus(409)
            ->assertSee('Sign the waiver before you pay.');
        $this->assertSame([], $fake->sessions);
        $this->assertHeld($workspace['tenant'], $bookingId, $total);

        $sign = $this->signAction((string) $checkout->getContent());
        $this->post($sign, [
            'agree' => ['booking' => '1'],
            'signer_name' => 'Ada Lovelace',
            'signer_minor' => '1',
        ], ['User-Agent' => 'PhoneBrowser/1.0'])
            ->assertStatus(422)
            ->assertSee('A person under 18 needs a parent or guardian to sign. Enter their name.');
        $this->assertSame(0, $this->signatureCount($workspace['tenant']));

        $this->post($sign, ['agree' => ['booking' => '1'], 'signer_name' => '   '])
            ->assertStatus(422)
            ->assertSee('Enter the full name of the person taking part.');

        $signed = $this->post($sign, [
            'agree' => ['booking' => '1'],
            'signer_name' => 'Ada Lovelace',
        ], ['User-Agent' => 'PhoneBrowser/1.0'])->assertRedirect();
        $review = $this->get((string) $signed->headers->get('Location'))->assertOk();
        $review->assertSee('Waiver signed', false)->assertSee('Pay with card')->assertSee('Pay at pickup')->assertDontSee('data-pay="locked"', false)->assertDontSee('Sign the waiver above to continue to payment.');

        app(TenantManager::class)->set($workspace['tenant']);
        $rows = WaiverSignature::query()->orderBy('participant_name')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [hash('sha256', self::LIABILITY), hash('sha256', self::PHOTO)],
            $rows->pluck('content_hash')->all(),
        );
        $this->assertTrue($rows->every(fn (WaiverSignature $row): bool => $row->participant_name === 'Ada Lovelace'
            && $row->user_agent === 'PhoneBrowser/1.0'
            && $row->ip_address !== null
            && $row->signed_at !== null));
        app(TenantManager::class)->forget();
        $this->assertHeld($workspace['tenant'], $bookingId, $total);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Payment::query()->where('booking_id', $bookingId)->count());
        app(TenantManager::class)->forget();

        $this->post($this->formAction((string) $review->getContent(), 'Pay with card'))
            ->assertRedirect('https://checkout.stripe.test/pay/cs_test_123');
        $this->assertCount(1, $fake->sessions);
        app(TenantManager::class)->set($workspace['tenant']);
        $paid = Booking::query()->findOrFail($bookingId);
        $this->assertSame('pending_payment', $paid->status->value);
        $this->assertNotSame('due_at_pickup', $paid->payment_status->value);
        app(TenantManager::class)->forget();
        $this->assertSame($total, $this->customerTotal($workspace['tenant'], $bookingId));
    }

    public function test_a_named_child_signs_before_payment_and_a_missing_guardian_is_refused(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->publish($workspace['tenant'], 'Liability waiver', self::LIABILITY);

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$this->token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '16:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'child-hold',
        ])->assertRedirect();
        $checkoutUrl = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($checkoutUrl);
        $total = $this->customerTotal($workspace['tenant'], $bookingId);
        $checkout = $this->get($checkoutUrl)->assertOk();
        $addUrl = $this->addAction((string) $checkout->getContent());

        $named = $this->post($addUrl, [
            'name' => 'Kid Quinn',
            'is_minor' => '1',
            'guardian_name' => 'Ada Quinn',
            'return' => 'checkout',
            'idempotency_key' => 'kid',
        ])->assertRedirect();
        $review = $this->get((string) $named->headers->get('Location'))->assertOk();
        $review->assertSee('Kid Quinn')->assertSee('Signed by Ada Quinn as parent or guardian.')->assertSee('data-pay="locked"', false);

        $sign = $this->signAction((string) $review->getContent());
        app(TenantManager::class)->set($workspace['tenant']);
        $participantId = (string) Booking::query()->findOrFail($bookingId)->namedParticipants()->value('id');
        app(TenantManager::class)->forget();

        $this->post($sign, [])->assertStatus(422)->assertSee('Tick the box to agree to the waiver.');
        $this->post($sign, ['agree' => [$participantId => '1']])->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $signature = WaiverSignature::query()->sole();
        $this->assertSame($participantId, $signature->participant_id);
        $this->assertSame('Kid Quinn', $signature->participant_name);
        $this->assertTrue($signature->is_minor);
        $this->assertSame('Ada Quinn', $signature->guardian_name);
        $this->assertSame(hash('sha256', self::LIABILITY), $signature->content_hash);
        app(TenantManager::class)->forget();

        $this->get((string) $named->headers->get('Location'))->assertOk()->assertSee('Pay with card');
        $this->assertSame($total, $this->customerTotal($workspace['tenant'], $bookingId));
    }

    public function test_another_workspace_cannot_sign_or_see_the_waiver(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->publish($workspace['tenant'], 'Liability waiver', self::LIABILITY);
        $other = $this->workspace('other.test');
        $this->publish($other['tenant'], 'Other waiver', 'This text belongs to the other shop.');

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$this->token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '17:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'isolation-hold',
        ])->assertRedirect();
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));
        $sign = $this->signed('widget.waivers', $workspace['tenant'], $bookingId);

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee(self::LIABILITY)
            ->assertDontSee('This text belongs to the other shop.');
        $this->post(str_replace('dock.test', 'other.test', $sign), [
            'agree' => ['booking' => '1'],
            'signer_name' => 'Mallory',
        ])->assertNotFound();
        $this->assertSame(0, WaiverSignature::withoutGlobalScopes()->count());
    }

    private function publish(Tenant $tenant, string $name, string $body): void
    {
        app(TenantManager::class)->set($tenant);
        WaiverVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'version' => 1,
            'body' => $body,
            'content_hash' => hash('sha256', $body),
        ]);
        app(TenantManager::class)->forget();
    }

    private function signed(string $name, Tenant $tenant, string $bookingId): string
    {
        app(TenantManager::class)->set($tenant);
        $hold = Hold::query()->where('booking_id', $bookingId)->firstOrFail();
        $expires = CarbonImmutable::instance($hold->expires_at);
        app(TenantManager::class)->forget();
        URL::forceRootUrl('http://dock.test');

        try {
            return URL::temporarySignedRoute($name, $expires, [
                'token' => $this->token,
                'booking' => $bookingId,
            ]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function assertHeld(Tenant $tenant, string $bookingId, int $total): void
    {
        app(TenantManager::class)->set($tenant);
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame('held', $booking->status->value);
        $this->assertNotSame('due_at_pickup', $booking->payment_status->value);
        app(TenantManager::class)->forget();
        $this->assertSame($total, $this->customerTotal($tenant, $bookingId));
    }

    private function signatureCount(Tenant $tenant): int
    {
        app(TenantManager::class)->set($tenant);
        $count = WaiverSignature::query()->count();
        app(TenantManager::class)->forget();

        return $count;
    }

    private function signAction(string $html): string
    {
        $this->assertSame(1, preg_match('/<form method="post" action="([^"]+)" data-waiver-sign>/', $html, $matches));

        return html_entity_decode($matches[1]);
    }

    private function formAction(string $html, string $button): string
    {
        $pattern = '/<form method="post" action="([^"]+)"[^>]*>\s*<button[^>]*>'.preg_quote($button, '/').'/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing the '.$button.' form.');

        return html_entity_decode($matches[1]);
    }

    private function addAction(string $html): string
    {
        $this->assertSame(1, preg_match('/<form method="post" action="([^"]+)" class="party-add">/', $html, $matches));

        return html_entity_decode($matches[1]);
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    private function customerTotal(Tenant $tenant, string $bookingId): int
    {
        app(TenantManager::class)->set($tenant);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        app(TenantManager::class)->forget();
        $total = $snapshot->payload['customer_total_cents'] ?? null;
        $this->assertIsInt($total);

        return $total;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(string $host, bool $cardAndPickup = false): array
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

        if ($cardAndPickup) {
            TenantSetting::query()->update(['pay_at_pickup' => true]);
            StripeAccount::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'stripe_account_id' => 'acct_test_123',
                'card_payments_status' => 'active',
                'livemode' => false,
            ]);
        }

        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $token = $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $this->assertIsString($token);
        $this->token = $token;

        return [$workspace, $service];
    }
}
