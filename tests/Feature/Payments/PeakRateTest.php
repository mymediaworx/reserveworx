<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PeakRateTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_peak_window_replaces_the_open_rate_and_is_stored_on_the_snapshot(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'full', '2026-06-15T14:00:00Z', '2026-06-15T16:00:00Z');
        $this->rates($service->id);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Afternoon peak',
            'kind' => 'base',
            'amount_cents' => 16_000,
            'local_start' => '10:00',
            'local_end' => '14:00',
            'weekdays' => [5, 1, 4, 2, 3],
        ])->assertCreated();
        $created->assertJsonPath('data.local_start', '10:00')
            ->assertJsonPath('data.local_end', '14:00')
            ->assertJsonPath('data.weekdays', [1, 2, 3, 4, 5]);

        $priced = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->json('data');

        $this->assertSame(16_000, $priced['payload']['services_cents']);
        $this->assertSame(480, $priced['payload']['fee_cents']);
        $this->assertSame(16_480, $priced['payload']['customer_total_cents']);
        $this->assertSame('10:00', $priced['payload']['priced_local_start']);
        $this->assertSame('10:00', $priced['payload']['time_windows'][0]['local_start']);
        $this->assertSame(120, $priced['payload']['time_windows'][0]['minutes']);
        $this->assertSame(16_000, $priced['payload']['time_windows'][0]['amount_cents']);
    }

    public function test_a_booking_that_crosses_the_peak_is_prorated_by_the_minute(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'cross', '2026-06-15T13:30:00Z', '2026-06-15T15:00:00Z');
        $this->rates($service->id);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Afternoon peak',
            'kind' => 'base',
            'amount_cents' => 16_000,
            'local_start' => '10:00',
            'local_end' => '14:00',
        ])->assertCreated();

        $priced = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(14_000, $priced['services_cents']);
        $this->assertSame(420, $priced['fee_cents']);
        $this->assertSame(60, $priced['time_windows'][0]['minutes']);
        $this->assertSame('09:30', $priced['priced_local_start']);
    }

    public function test_spring_forward_skips_the_missing_local_hour(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 6_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Early peak',
            'kind' => 'base',
            'amount_cents' => 12_000,
            'local_start' => '01:00',
            'local_end' => '03:00',
        ])->assertCreated();

        $springId = $this->hold($service, 'spring', '2026-03-08T06:30:00Z', '2026-03-08T07:30:00Z');
        $spring = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$springId.'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(9_000, $spring['services_cents']);
        $this->assertSame(30, $spring['time_windows'][0]['minutes']);
        $this->assertSame('01:30', $spring['priced_local_start']);
    }

    public function test_fall_back_counts_both_occurrences_of_the_repeated_hour(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 6_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Repeated hour',
            'kind' => 'base',
            'amount_cents' => 12_000,
            'local_start' => '01:00',
            'local_end' => '02:00',
        ])->assertCreated();

        $fallId = $this->hold($service, 'fall', '2026-11-01T04:30:00Z', '2026-11-01T07:30:00Z');
        $fall = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$fallId.'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(10_000, $fall['services_cents']);
        $this->assertSame(120, $fall['time_windows'][0]['minutes']);
        $this->assertSame('00:30', $fall['priced_local_start']);
    }

    public function test_moving_into_the_peak_on_the_same_day_is_a_price_increase(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'move', '2026-06-15T13:00:00Z', '2026-06-15T14:00:00Z');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Late morning',
            'kind' => 'base',
            'amount_cents' => 20_000,
            'local_start' => '10:00',
            'local_end' => '12:00',
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.services_cents', 10_000)
            ->assertJsonPath('data.payload.time_windows', []);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/offline-payment'))
            ->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $booking->payment_status = PaymentStatus::Paid;
        $booking->save();
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'starts_at' => '2026-06-15T14:00:00Z',
            'ends_at' => '2026-06-15T15:00:00Z',
        ])->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Online payments are not enabled for this workspace.');
    }

    public function test_a_peak_rate_stays_inside_its_workspace(): void
    {
        [$alpha, $alphaService] = $this->shop('alpha.test');
        [$beta, $betaService] = $this->shop('beta.test');
        $this->actingAsWorkspace($alpha);
        $alphaBooking = $this->hold($alphaService, 'alpha', '2026-06-15T14:00:00Z', '2026-06-15T15:00:00Z', 'alpha.test');
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$alphaService->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();

        $this->actingAsWorkspace($beta);
        $betaBooking = $this->hold($betaService, 'beta', '2026-06-15T14:00:00Z', '2026-06-15T15:00:00Z', 'beta.test');
        $this->postJson($this->tenantUrl('beta.test', '/api/v1/services/'.$betaService->id.'/prices'), [
            'name' => 'Peak',
            'kind' => 'base',
            'amount_cents' => 50_000,
            'local_start' => '09:00',
            'local_end' => '17:00',
        ])->assertCreated();

        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('beta.test', '/api/v1/services/'.$betaService->id.'/prices'), [
            'name' => 'Stolen peak',
            'kind' => 'base',
            'amount_cents' => 1,
            'local_start' => '09:00',
            'local_end' => '17:00',
        ])->assertForbidden();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$betaService->id.'/prices'), [
            'name' => 'Stolen peak',
            'kind' => 'base',
            'amount_cents' => 1,
            'local_start' => '09:00',
            'local_end' => '17:00',
        ])->assertNotFound();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$betaBooking.'/pricing'))
            ->assertNotFound();

        $alphaPrice = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$alphaBooking.'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(10_000, $alphaPrice['services_cents']);
        $this->assertSame([], $alphaPrice['time_windows']);

        $this->actingAsWorkspace($beta);
        $betaPrice = $this->postJson($this->tenantUrl('beta.test', '/api/v1/bookings/'.$betaBooking.'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(50_000, $betaPrice['services_cents']);
        $this->assertSame(60, $betaPrice['time_windows'][0]['minutes']);

        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->where('service_id', $betaService->id)
            ->count());
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(1, PriceRule::query()->count());
        $this->assertSame(0, PriceRule::query()->where('tenant_id', $alpha['tenant']->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_clock_window_must_be_a_real_span_on_fitting_weekdays(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $url = $this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices');

        $this->postJson($url, [
            'name' => 'Missing end',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'local_start' => '10:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['local_start']);

        $this->postJson($url, [
            'name' => 'Empty span',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'local_start' => '10:00',
            'local_end' => '10:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['local_end']);

        $this->postJson($url, [
            'name' => 'Days only',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'weekdays' => [1],
        ])->assertStatus(422)->assertJsonValidationErrors(['weekdays']);

        $this->postJson($url, [
            'name' => 'Weekend Monday',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'window' => 'weekend',
            'local_start' => '10:00',
            'local_end' => '12:00',
            'weekdays' => [1],
        ])->assertStatus(422)->assertJsonValidationErrors(['weekdays']);

        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->count());
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

    private function rates(string $serviceId): void
    {
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$serviceId.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$serviceId.'/prices'), [
            'name' => 'Weekend',
            'kind' => 'base',
            'amount_cents' => 20_000,
            'window' => 'weekend',
        ])->assertCreated();
    }

    private function hold(Service $service, string $suffix, string $starts, string $ends, string $host = 'dock.test'): string
    {
        return $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 2,
            'idempotency_key' => 'peak-'.$suffix.'-'.$service->id,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
    }
}
