<?php

namespace Tests\Feature\Widget;

use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestMultiDayRescheduleTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    private const ZONE = 'America/New_York';

    private const TWO_DAYS = 2880;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_guest_moves_a_two_day_rental_and_keeps_its_length_and_price(): void
    {
        [$workspace, $duration] = $this->shop('2026-06-15 12:00:00');
        $bookingId = $this->confirmed($workspace, $duration, '2026-06-16', '10:00', 'two-day');
        $manage = $this->manageUrl($workspace['tenant'], $bookingId);

        $this->get($manage)
            ->assertOk()
            ->assertSee('Length: 2 days')
            ->assertSee('The price stays the same.')
            ->assertSee('Tue, Jun 16, 10:00 AM – Thu, Jun 18, 10:00 AM EDT')
            ->assertSee('value="2026-06-16"', false)
            ->assertSee('value="10:00"', false)
            ->assertDontSee('Duration in minutes')
            ->assertDontSee('2026-06-16T14:00:00')
            ->assertDontSee('value="14:00"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $before = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $payload = $before->payload;
        app(TenantManager::class)->forget();

        $this->post($this->rescheduleUrl($manage), [
            'date' => '2026-06-20',
            'start' => '10:00',
        ])->assertOk()
            ->assertSee('Length: 2 days')
            ->assertSee('Sat, Jun 20, 10:00 AM – Mon, Jun 22, 10:00 AM EDT')
            ->assertSee('value="2026-06-20"', false)
            ->assertSee('value="10:00"', false)
            ->assertDontSee('2026-06-20T14:00:00');

        app(TenantManager::class)->set($workspace['tenant']);
        $moved = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-06-20 14:00:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-22 14:00:00', $moved->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($payload, PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        app(TenantManager::class)->forget();

        $this->workspace('creek.test');
        $this->get(str_replace('http://dock.test', 'http://creek.test', $manage))
            ->assertNotFound()
            ->assertDontSee('Length: 2 days');
    }

    public function test_a_two_day_move_across_the_spring_forward_keeps_elapsed_minutes(): void
    {
        [$workspace, $duration] = $this->shop('2026-03-01 15:00:00');
        $bookingId = $this->confirmed($workspace, $duration, '2026-03-04', '10:00', 'before-change');

        $this->post($this->rescheduleUrl($this->manageUrl($workspace['tenant'], $bookingId)), [
            'date' => '2026-03-07',
            'start' => '10:00',
        ])->assertOk()
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertSee('value="2026-03-07"', false)
            ->assertSee('value="10:00"', false)
            ->assertDontSee('2026-03-07T15:00:00');

        app(TenantManager::class)->set($workspace['tenant']);
        $moved = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-03-07 15:00:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $moved->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(self::TWO_DAYS * 60, $moved->ends_at->utc()->getTimestamp() - $moved->starts_at->utc()->getTimestamp());
        app(TenantManager::class)->forget();
    }

    public function test_the_manage_page_shows_november_in_eastern_standard_time(): void
    {
        [$workspace, $duration] = $this->shop('2026-11-01 15:00:00');
        $bookingId = $this->confirmed($workspace, $duration, '2026-11-03', '10:00', 'november');
        $manage = $this->manageUrl($workspace['tenant'], $bookingId);

        $this->get($manage)
            ->assertOk()
            ->assertSee('Tue, Nov 3, 10:00 AM – Thu, Nov 5, 10:00 AM EST')
            ->assertSee('value="2026-11-03"', false)
            ->assertSee('value="10:00"', false)
            ->assertDontSee('2026-11-03T15:00:00')
            ->assertDontSee('value="15:00"', false);

        $this->post($this->rescheduleUrl($manage), [
            'date' => '2026-11-10',
            'start' => '10:00',
        ])->assertOk()
            ->assertSee('Tue, Nov 10, 10:00 AM – Thu, Nov 12, 10:00 AM EST')
            ->assertSee('value="2026-11-10"', false)
            ->assertSee('value="10:00"', false)
            ->assertDontSee('2026-11-10T15:00:00');
    }

    public function test_a_different_length_a_taken_time_or_a_closed_day_leaves_the_rental(): void
    {
        [$workspace, $duration] = $this->shop('2026-06-15 12:00:00');
        $bookingId = $this->confirmed($workspace, $duration, '2026-06-16', '10:00', 'stay');
        $manage = $this->manageUrl($workspace['tenant'], $bookingId);
        $reschedule = $this->rescheduleUrl($manage);

        $this->post($reschedule, [
            'date' => '2026-06-20',
            'start' => '10:00',
            'duration_minutes' => 60,
        ])->assertStatus(422)->assertSee('This rental keeps its current length.');

        $this->hold($workspace, $duration, '2026-06-20', '10:00', 'taken');
        $taken = $this->post($reschedule, [
            'date' => '2026-06-20',
            'start' => '10:00',
        ])->assertStatus(409);
        $taken->assertSee('That time is no longer available.')->assertSee('Other times')->assertSee('Mon, Jun 22, 10:00 AM');

        app(TenantManager::class)->set($workspace['tenant']);
        Blackout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => Booking::query()->findOrFail($bookingId)->location_id,
            'starts_on' => '2026-06-24',
            'ends_on' => '2026-06-24',
            'reason' => 'Storm',
        ]);
        app(TenantManager::class)->forget();

        $this->post($reschedule, [
            'date' => '2026-06-23',
            'start' => '10:00',
        ])->assertStatus(409)->assertSee('That date is closed.')->assertDontSee('Other times');

        app(TenantManager::class)->set($workspace['tenant']);
        $kept = Booking::query()->findOrFail($bookingId);
        $this->assertSame('2026-06-16 14:00:00', $kept->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-18 14:00:00', $kept->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function confirmed(array $workspace, ServiceDuration $duration, string $date, string $start, string $key): string
    {
        $bookingId = $this->hold($workspace, $duration, $date, $start, $key);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function hold(array $workspace, ServiceDuration $duration, string $date, string $start, string $key): string
    {
        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $response = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$duration->service_id), [
            'date' => $date,
            'start' => $start,
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ])->assertRedirect();
        preg_match('#/checkout/([^?]+)#', (string) $response->headers->get('Location'), $matches);

        return $matches[1];
    }

    private function manageUrl(Tenant $tenant, string $bookingId): string
    {
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.app(WidgetTokens::class)->hostedPlain($tenant).'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();

        return (string) $found->headers->get('Location');
    }

    private function rescheduleUrl(string $manage): string
    {
        $page = $this->get($manage)->assertOk();
        preg_match('/action="([^"]*reschedule[^"]*)"/', (string) $page->getContent(), $action);

        return html_entity_decode($action[1]);
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: ServiceDuration}
     */
    private function shop(string $now): array
    {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 1, self::ZONE);
        $duration = ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $shop['service']->id,
            'name' => '2 days',
            'quantity' => 48,
            'unit' => 'hours',
            'duration_minutes' => self::TWO_DAYS,
        ]);

        foreach ([0, 1, 2, 3, 4, 5, 6] as $weekday) {
            AvailabilitySchedule::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'owner_type' => 'location',
                'owner_id' => $shop['location']->id,
                'weekday' => $weekday,
                'opens_local' => '08:00:00',
                'closes_local' => '18:00:00',
            ]);
        }

        app(TenantManager::class)->forget();

        return [$workspace, $duration];
    }
}
