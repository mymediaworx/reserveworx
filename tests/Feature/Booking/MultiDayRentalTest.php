<?php

namespace Tests\Feature\Booking;

use App\Livewire\Staff\NewBooking;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\SlotFinder;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class MultiDayRentalTest extends TestCase
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

    public function test_the_public_page_lists_and_holds_a_two_day_rental(): void
    {
        [$workspace, $shop, $duration] = $this->shop();
        $page = $this->page($workspace['tenant'], $shop['service']);

        $times = $this->get($this->query($page, $duration, '2026-06-16'))->assertOk();
        $html = (string) $times->getContent();
        $times->assertSee('2 days (2 days)')->assertSee('10:00 AM')->assertDontSee('No open times on this date');
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="08:00"[^>]*disabled/', $html));
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="10:00"(?![^>]*disabled)[^>]*>/', $html));
        $this->assertStringNotContainsString('value="18:00"', $html);

        $this->post($page, $this->hold('2026-06-16', '10:00', $duration, 'two-day'))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('held', $booking->status->value);
        $this->assertSame('2026-06-16 14:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-18 14:00:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();

        $this->get($this->query($page, $duration, '2026-06-17'))
            ->assertOk()
            ->assertSee('No open times on this date');
        $this->post($page, $this->hold('2026-06-17', '10:00', $duration, 'overlap'))
            ->assertStatus(409)
            ->assertSee('That time is no longer available.');
    }

    public function test_a_blackout_or_a_closed_return_day_refuses_the_pickup(): void
    {
        [$workspace, $shop, $duration] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        Blackout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'starts_on' => '2026-06-17',
            'ends_on' => '2026-06-17',
            'reason' => 'Storm',
        ]);
        app(TenantManager::class)->forget();

        $page = $this->page($workspace['tenant'], $shop['service']);
        $this->get($this->query($page, $duration, '2026-06-16'))
            ->assertOk()
            ->assertSee('No open times on this date');
        $this->post($page, $this->hold('2026-06-16', '10:00', $duration, 'blackout'))
            ->assertStatus(409)
            ->assertSee('That date is closed.');

        [$weekdayWorkspace, $weekdayShop, $weekdayDuration] = $this->shop(weekdays: range(1, 5), host: 'week.test');
        $weekdayPage = $this->page($weekdayWorkspace['tenant'], $weekdayShop['service'], 'week.test');
        $thursday = $this->get($this->query($weekdayPage, $weekdayDuration, '2026-06-18'))->assertOk();
        $thursday->assertSee('No open times on this date');
        $this->post($weekdayPage, $this->hold('2026-06-18', '10:00', $weekdayDuration, 'saturday-return'))
            ->assertStatus(409)
            ->assertSee('That time is outside opening hours.');

        $wednesday = $this->get($this->query($weekdayPage, $weekdayDuration, '2026-06-17'))->assertOk();
        $this->assertSame(
            1,
            preg_match('/<input type="radio" name="start" value="10:00"(?![^>]*disabled)[^>]*>/', (string) $wednesday->getContent()),
        );
    }

    public function test_a_two_day_rental_keeps_elapsed_minutes_across_the_spring_forward(): void
    {
        [$workspace, $shop, $duration] = $this->shop(now: '2026-03-01 15:00:00');
        $page = $this->page($workspace['tenant'], $shop['service']);

        $this->get($this->query($page, $duration, '2026-03-07'))->assertOk()->assertSee('10:00 AM');
        $this->post($page, $this->hold('2026-03-07', '10:00', $duration, 'spring'))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('2026-03-07 15:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('11:00', $booking->ends_at->timezone(self::ZONE)->format('H:i'));
    }

    public function test_a_two_day_rental_keeps_elapsed_minutes_across_the_fall_back(): void
    {
        [$workspace, $shop, $duration] = $this->shop(now: '2026-10-01 15:00:00');
        $page = $this->page($workspace['tenant'], $shop['service']);

        $this->get($this->query($page, $duration, '2026-10-31'))->assertOk()->assertSee('10:00 AM');
        $this->post($page, $this->hold('2026-10-31', '10:00', $duration, 'fall'))->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('2026-10-31 14:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('09:00', $booking->ends_at->timezone(self::ZONE)->format('H:i'));
    }

    public function test_staff_can_hold_the_same_two_day_length(): void
    {
        [$workspace, $shop] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $start = CarbonImmutable::parse('2026-06-16 10:00:00', self::ZONE)->utc();

        $component = Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', '2026-06-16')
            ->set('durationMinutes', self::TWO_DAYS)
            ->assertSee('2 days')
            ->assertSee('10:00 AM')
            ->assertDontSee('No start times left on this date.')
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada Lovelace')
            ->set('customerEmail', 'ada@example.com')
            ->call('book')
            ->assertHasNoErrors();

        $booking = Booking::query()->firstOrFail();
        $component->assertRedirect('/staff/bookings/'.$booking->id);
        $this->assertSame('held', $booking->status->value);
        $this->assertSame('2026-06-18 14:00:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_hourly_rental_still_has_to_finish_before_close(): void
    {
        [$workspace, $shop] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        $slots = app(SlotFinder::class)->rentalSlots(
            $shop['service']->fresh('location'),
            '2026-06-16',
            60,
            1,
            CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'),
        );
        $labels = array_column($slots, 'label');

        $this->assertContains('5:00 PM', $labels);
        $this->assertNotContains('5:30 PM', $labels);
    }

    /**
     * @param  list<int>  $weekdays
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: array{location: Location, service: Service}, 2: ServiceDuration}
     */
    private function shop(string $now = '2026-06-15 12:00:00', array $weekdays = [0, 1, 2, 3, 4, 5, 6], string $host = 'dock.test'): array
    {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $workspace = $this->workspace($host);
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

        foreach ($weekdays as $weekday) {
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

        return [$workspace, $shop, $duration];
    }

    private function page(Tenant $tenant, Service $service, string $host = 'dock.test'): string
    {
        $token = app(WidgetTokens::class)->hostedPlain($tenant);

        return $this->tenantUrl($host, '/book/'.$token.'/services/'.$service->id);
    }

    private function query(string $page, ServiceDuration $duration, string $date): string
    {
        return $page.'?'.http_build_query([
            'date' => $date,
            'duration_id' => $duration->id,
            'participants' => 1,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function hold(string $date, string $start, ServiceDuration $duration, string $key): array
    {
        return [
            'date' => $date,
            'start' => $start,
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ];
    }
}
