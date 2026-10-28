<?php

namespace Tests\Feature\Widget;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestRescheduleDaylightSavingTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_guest_keeps_both_fall_back_hours_and_a_missing_hour_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('creek.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 1, 'America/New_York');
        ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $shop['service']->id,
            'name' => '30 min',
            'quantity' => 30,
            'unit' => 'minutes',
            'duration_minutes' => 30,
        ]);
        app(TenantManager::class)->forget();
        app(TenantManager::class)->set($other['tenant']);
        $creek = $this->rentalShop($other['tenant'], 1, 'America/New_York');
        app(TenantManager::class)->forget();

        $bookingId = $this->confirmed($workspace, $shop['service']->id, '2026-11-02T10:00:00-05:00', '2026-11-02T10:30:00-05:00', 'ada@example.com', 'guest-day');
        $blockerId = $this->confirmed($workspace, $shop['service']->id, '2026-11-01T02:00:00-05:00', '2026-11-01T02:30:00-05:00', 'bea@example.com', 'blocker');
        $creekId = $this->confirmed($other, $creek['service']->id, '2026-11-02T10:00:00-05:00', '2026-11-02T11:00:00-05:00', 'creek@example.com', 'creek-day', 'creek.test');

        $manage = $this->manageUrl($bookingId);
        $reschedule = $this->rescheduleUrl($manage);
        $money = $this->moneyCounts();
        $audits = $this->rows(AuditLog::class);

        $this->get($manage)
            ->assertOk()
            ->assertSee('Mon, Nov 2, 10:00 AM – 10:30 AM EST', false)
            ->assertSee('data-local-start="10:00"', false)
            ->assertSee('type="time"', false)
            ->assertDontSee('This start happens twice.')
            ->assertDontSee('bea@example.com')
            ->assertDontSee('creek@example.com')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false);

        $taken = $this->post($reschedule, [
            'date' => '2026-11-01',
            'start' => '02:00',
        ])->assertStatus(409);
        $taken->assertSee('That time is no longer available.')
            ->assertSee('Other times')
            ->assertSee('Sun, Nov 1, 1:30 AM – 2:00 AM EST', false)
            ->assertSee('data-offer-start="01:30-05:00"', false)
            ->assertDontSee('1:30 AM EDT')
            ->assertDontSee('bea@example.com')
            ->assertDontSee('creek@example.com');

        $this->assertSame('2026-11-02 15:00:00', $this->stamp($bookingId, 'starts_at'));
        $this->assertSame('2026-11-02 15:30:00', $this->stamp($bookingId, 'ends_at'));
        $this->assertSame(0, $this->hooks());

        $later = $this->post($reschedule, [
            'date' => '2026-11-01',
            'start' => '01:30-05:00',
        ])->assertOk();
        $later->assertSee('Sun, Nov 1, 1:30 AM – 2:00 AM EST', false)
            ->assertSee('data-local-start="01:30-05:00"', false)
            ->assertSee('type="text"', false)
            ->assertSee('This start happens twice. The offset keeps this hour. A plain time is the earlier one.')
            ->assertDontSee('1:30 AM EDT');
        $this->assertSame('2026-11-01 06:30:00', $this->stamp($bookingId, 'starts_at'));
        $this->assertSame('2026-11-01 07:00:00', $this->stamp($bookingId, 'ends_at'));

        $earlier = $this->post($reschedule, [
            'date' => '2026-11-01',
            'start' => '01:30',
        ])->assertOk();
        $earlier->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:00 AM EST', false)
            ->assertSee('data-local-start="01:30-04:00"', false);
        $this->assertSame('2026-11-01 05:30:00', $this->stamp($bookingId, 'starts_at'));
        $this->assertSame('2026-11-01 06:00:00', $this->stamp($bookingId, 'ends_at'));

        $this->post($reschedule, [
            'date' => '2026-03-08',
            'start' => '02:30',
        ])->assertStatus(422)
            ->assertSee('That time does not exist in America/New_York.')
            ->assertDontSee('Sun, Mar 8, 3:00 AM');
        $this->assertSame('2026-11-01 05:30:00', $this->stamp($bookingId, 'starts_at'));

        $this->post($reschedule, [
            'date' => '2026-11-01',
            'start' => '01:30-07:00',
        ])->assertStatus(422)
            ->assertSee('Choose a valid date and time.');
        $this->assertSame('2026-11-01 05:30:00', $this->stamp($bookingId, 'starts_at'));
        $this->assertSame('2026-11-01 06:00:00', $this->stamp($bookingId, 'ends_at'));
        $this->assertSame(2, $this->hooks());

        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['timezone' => 'America/Chicago']);
        Location::query()->whereKey($shop['location']->id)->update(['timezone' => 'Not/AZone']);
        app(TenantManager::class)->forget();

        $fallback = $this->post($reschedule, [
            'date' => '2026-11-01',
            'start' => '01:30-06:00',
        ])->assertOk();
        $fallback->assertSee('Sun, Nov 1, 1:30 AM – 2:00 AM CST', false)
            ->assertSee('data-local-start="01:30-06:00"', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('creek@example.com');
        $this->assertSame('2026-11-01 07:30:00', $this->stamp($bookingId, 'starts_at'));
        $this->assertSame('2026-11-01 08:00:00', $this->stamp($bookingId, 'ends_at'));

        $this->assertSame('2026-11-01 07:00:00', $this->stamp($blockerId, 'starts_at'));
        $this->assertSame('2026-11-01 07:30:00', $this->stamp($blockerId, 'ends_at'));
        $this->assertSame('2026-11-02 15:00:00', $this->stamp($creekId, 'starts_at'));
        $this->assertSame($money, $this->moneyCounts());
        $this->assertSame($audits, $this->rows(AuditLog::class));
        $this->assertSame(3, $this->hooks());
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function confirmed(array $workspace, string $serviceId, string $start, string $end, string $email, string $key, string $host = 'dock.test'): string
    {
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $serviceId,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => $email,
            'idempotency_key' => $key,
        ])->assertCreated();
        $bookingId = $created->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    private function manageUrl(string $bookingId): string
    {
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.app(WidgetTokens::class)->hostedPlain(
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId)->tenant,
        ).'/lookup'), [
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

    private function stamp(string $bookingId, string $column): string
    {
        $booking = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId);

        return $booking->{$column}->utc()->format('Y-m-d H:i:s');
    }

    private function hooks(): int
    {
        return NotificationHook::withoutGlobalScope(TenantScope::class)
            ->where('kind', 'booking.guest_rescheduled')
            ->count();
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, snapshots: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => $this->rows(PriceRule::class),
            'payments' => $this->rows(Payment::class),
            'fees' => $this->rows(PlatformFee::class),
            'fee_refunds' => $this->rows(PlatformFeeRefund::class),
            'refunds' => $this->rows(Refund::class),
            'snapshots' => $this->rows(PricingSnapshot::class),
            'taxes' => $this->rows(LocationTaxRule::class),
        ];
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
