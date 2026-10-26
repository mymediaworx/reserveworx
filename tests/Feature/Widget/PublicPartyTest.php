<?php

namespace Tests\Feature\Widget;

use App\Enums\BookingStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Participant;
use App\Models\PricingSnapshot;
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

class PublicPartyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

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

    public function test_a_guest_names_each_person_before_payment_without_changing_the_price(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$this->token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'party-hold',
        ])->assertRedirect();
        $checkoutUrl = $held->headers->get('Location');
        $this->assertIsString($checkoutUrl);
        $bookingId = $this->bookingId($checkoutUrl);

        $checkout = $this->get($checkoutUrl)->assertOk()->assertSee('Who is coming')->assertSee('0 of 2 people named');
        $addUrl = $this->addAction((string) $checkout->getContent());
        $total = $this->customerTotal($workspace['tenant'], $bookingId);

        $this->post($addUrl, [
            'name' => '   ',
            'return' => 'checkout',
            'idempotency_key' => 'blank',
        ])->assertStatus(422)->assertSee('A participant needs a name.', false);
        $this->assertSame(0, $this->namedCount($workspace['tenant'], $bookingId));

        $this->post($addUrl, [
            'name' => 'Sam Quinn',
            'is_minor' => '1',
            'return' => 'checkout',
            'idempotency_key' => 'minor-missing',
        ])->assertStatus(422)->assertSee('A minor needs a parent or guardian on their record.', false);
        $this->assertSame(0, $this->namedCount($workspace['tenant'], $bookingId));

        $adult = $this->post($addUrl, [
            'name' => '  Ada Quinn  ',
            'guardian_name' => 'Should be ignored',
            'return' => 'checkout',
            'idempotency_key' => 'adult-1',
        ])->assertRedirect();
        $again = $this->post($addUrl, [
            'name' => 'Ada Quinn',
            'guardian_name' => 'Still ignored',
            'return' => 'checkout',
            'idempotency_key' => 'adult-1',
        ])->assertRedirect();
        $this->post($addUrl, [
            'name' => 'Someone else',
            'return' => 'checkout',
            'idempotency_key' => 'adult-1',
        ])->assertStatus(409)->assertSee('That idempotency key was already used for a different request.', false);
        $this->assertSame(1, $this->namedCount($workspace['tenant'], $bookingId));

        $minor = $this->post($addUrl, [
            'name' => 'Sam Quinn',
            'is_minor' => '1',
            'guardian_name' => '  Ada Quinn  ',
            'return' => 'confirmation',
            'idempotency_key' => 'minor-1',
        ])->assertRedirect();
        $confirmationUrl = $minor->headers->get('Location');
        $this->assertIsString($confirmationUrl);

        $full = $this->get($confirmationUrl)->assertOk()
            ->assertSee('Ada Quinn')
            ->assertSee('Sam Quinn')
            ->assertSee('Under 18, guardian Ada Quinn')
            ->assertSee('Everyone in this party is named.')
            ->assertDontSee('Add this person');
        $this->assertSame(2, $this->namedCount($workspace['tenant'], $bookingId));
        $this->assertSame($total, $this->customerTotal($workspace['tenant'], $bookingId));
        $this->assertSame(2, $this->partySize($workspace['tenant'], $bookingId));

        app(TenantManager::class)->set($workspace['tenant']);
        $adultId = Participant::query()->where('booking_id', $bookingId)->where('name', 'Ada Quinn')->value('id');
        $minorId = Participant::query()->where('booking_id', $bookingId)->where('name', 'Sam Quinn')->value('id');
        $storedAdult = Participant::query()->where('name', 'Ada Quinn')->firstOrFail();
        $this->assertFalse($storedAdult->is_minor);
        $this->assertNull($storedAdult->guardian_name);
        app(TenantManager::class)->forget();
        $this->assertIsString($adultId);
        $this->assertIsString($minorId);

        $this->post($addUrl, [
            'name' => 'Extra Person',
            'return' => 'checkout',
            'idempotency_key' => 'extra',
        ])->assertStatus(422)->assertSee('This booking already names everyone in the party.', false);
        $this->assertSame(2, $this->namedCount($workspace['tenant'], $bookingId));

        $this->post($this->removeAction((string) $full->getContent(), $adultId), [
            'return' => 'confirmation',
        ])->assertRedirect();
        $this->assertSame(1, $this->namedCount($workspace['tenant'], $bookingId));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant.added',
            'tenant_id' => $workspace['tenant']->id,
            'subject_id' => $minorId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant.removed',
            'tenant_id' => $workspace['tenant']->id,
            'subject_id' => $adultId,
        ]);

        $waiver = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waiver-versions'), [
            'name' => 'Rental waiver',
            'body' => 'I accept the risks of this rental.',
        ])->assertCreated()->json('data');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/waivers'), [
            'waiver_version_id' => $waiver['id'],
            'participant_id' => $minorId,
        ])->assertCreated();

        $afterRemoval = $this->get($confirmationUrl)->assertOk()->assertSee('Add this person');
        $this->post($this->removeAction((string) $afterRemoval->getContent(), $minorId), [
            'return' => 'confirmation',
        ])->assertStatus(409)->assertSee('A signed waiver keeps this participant on the booking.', false);
        $this->assertSame(1, $this->namedCount($workspace['tenant'], $bookingId));

        $this->post(strtok($addUrl, '?') ?: $addUrl, [
            'name' => 'No signature',
            'return' => 'checkout',
        ])->assertForbidden();

        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertSee('Sam Quinn')
            ->assertSee('under 18, Ada Quinn', false);

        $this->assertNotNull($adult->headers->get('Location'));
        $this->assertNotNull($again->headers->get('Location'));
    }

    public function test_another_workspace_or_a_cancelled_booking_cannot_name_a_person(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$this->token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 2,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'party-hold-2',
        ])->assertRedirect();
        $checkoutUrl = $held->headers->get('Location');
        $this->assertIsString($checkoutUrl);
        $bookingId = $this->bookingId($checkoutUrl);
        $addUrl = $this->addAction((string) $this->get($checkoutUrl)->assertOk()->getContent());

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $otherToken = $this->postJson($this->tenantUrl('other.test', '/api/v1/widget-tokens'), [
            'name' => 'Other site',
        ])->assertCreated()->json('data.token');
        $this->assertIsString($otherToken);
        URL::forceRootUrl('http://other.test');
        $foreign = URL::temporarySignedRoute('widget.party.store', now()->addHour(), [
            'token' => $otherToken,
            'booking' => $bookingId,
        ]);
        URL::forceRootUrl(null);
        $this->post($foreign, [
            'name' => 'From elsewhere',
            'return' => 'checkout',
            'idempotency_key' => 'foreign',
        ])->assertNotFound();
        $this->assertSame(0, $this->namedCount($workspace['tenant'], $bookingId));

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        $first = Participant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $bookingId,
            'name' => 'Kept',
            'is_minor' => false,
            'payload_hash' => str_repeat('a', 64),
        ]);
        $secondBooking = Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $booking->location_id,
            'service_id' => $service->id,
            'status' => BookingStatus::Held,
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'participants' => 1,
            'payload_hash' => str_repeat('b', 64),
        ]);
        app(TenantManager::class)->forget();

        $this->post($addUrl, [
            'name' => 'Too late',
            'return' => 'checkout',
            'idempotency_key' => 'cancelled',
        ])->assertStatus(409)->assertSee('Only a held or confirmed booking can name a participant.', false);

        URL::forceRootUrl('http://dock.test');
        $removeOther = URL::temporarySignedRoute('widget.party.remove', now()->addHour(), [
            'token' => $this->token,
            'booking' => $secondBooking->id,
            'participant' => $first->id,
        ]);
        URL::forceRootUrl(null);
        $this->post($removeOther, ['return' => 'checkout'])->assertNotFound();
        $this->assertSame(1, $this->namedCount($workspace['tenant'], $bookingId));
    }

    private function addAction(string $html): string
    {
        $this->assertSame(1, preg_match('/<form method="post" action="([^"]+)" class="party-add">/', $html, $matches));

        return html_entity_decode($matches[1]);
    }

    private function removeAction(string $html, string $participantId): string
    {
        $pattern = '/data-participant="'.preg_quote($participantId, '/').'"[\s\S]*?<form method="post" action="([^"]+)" class="party-remove">/';
        $this->assertSame(1, preg_match($pattern, $html, $matches));

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

    private function namedCount(Tenant $tenant, string $bookingId): int
    {
        app(TenantManager::class)->set($tenant);

        $count = Participant::query()->where('booking_id', $bookingId)->count();
        app(TenantManager::class)->forget();

        return $count;
    }

    private function partySize(Tenant $tenant, string $bookingId): int
    {
        app(TenantManager::class)->set($tenant);
        $size = (int) Booking::query()->findOrFail($bookingId)->participants;
        app(TenantManager::class)->forget();

        return $size;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(string $host): array
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
