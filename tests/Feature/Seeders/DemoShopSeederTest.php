<?php

namespace Tests\Feature\Seeders;

use App\Models\AddOn;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WaiverVersion;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoShopSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_demo_tenants_get_a_bookable_shop_once(): void
    {
        $path = sys_get_temp_dir().'/reserveworx-demo-logins-'.bin2hex(random_bytes(4)).'.txt';
        putenv('DEMO_LOGIN_FILE='.$path);
        $_ENV['DEMO_LOGIN_FILE'] = $path;
        $_SERVER['DEMO_LOGIN_FILE'] = $path;

        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoShopSeeder::class);
        $this->seed(DemoShopSeeder::class);

        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        app(TenantManager::class)->set($harbor);

        $this->assertSame(1, Location::query()->count(), 'Running twice does not duplicate the shop.');
        $this->assertTrue(TenantSetting::query()->firstOrFail()->pay_at_pickup);
        $this->assertSame(['Paddleboard rental', 'Single kayak rental', 'Sunset paddle tour', 'Tandem kayak rental'], Service::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(7, AvailabilitySchedule::query()->count());
        $this->assertGreaterThan(0, PriceRule::query()->count());
        $this->assertSame(60, Departure::query()->count());
        $latest = Departure::query()->orderByDesc('starts_at')->first();
        $this->assertInstanceOf(Departure::class, $latest);
        $expected = CarbonImmutable::now('America/New_York')->startOfDay()->addDays(60)->setTime(18, 0)->utc();
        $this->assertTrue($latest->starts_at->equalTo($expected));
        $bookings = Booking::query()->count();
        $this->assertGreaterThan(0, $bookings);

        $latest->delete();
        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($harbor);
        $this->assertSame(60, Departure::query()->count());
        $this->assertSame($bookings, Booking::query()->count());
        $this->assertSame(1, Location::query()->count());

        $page = $this->get('http://harbor-demo.reserveworx.test/');
        $page->assertOk()
            ->assertSee('Single kayak rental')
            ->assertSee('Paddleboard rental')
            ->assertSee('Sunset paddle tour')
            ->assertDontSee('No services are available to book.');

        $inland = Tenant::query()->where('slug', 'inland-demo')->firstOrFail();
        app(TenantManager::class)->set($inland);
        $this->assertSame(['Canoe float'], Service::query()->pluck('name')->all());
        $this->assertTrue(TenantSetting::query()->firstOrFail()->pay_at_pickup);

        $real = Tenant::factory()->create(['slug' => 'real-marina', 'name' => 'Real Marina']);
        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($real);
        $this->assertSame(0, Location::query()->count());
        $this->assertSame(0, Service::query()->count());
        $this->assertFalse(TenantSetting::query()->firstOrFail()->pay_at_pickup);

        @unlink($path);
    }

    public function test_harbor_demo_gets_sample_waiver_add_ons_policy_tax_and_a_rolling_checkout(): void
    {
        $path = sys_get_temp_dir().'/reserveworx-demo-logins-'.bin2hex(random_bytes(4)).'.txt';
        putenv('DEMO_LOGIN_FILE='.$path);
        $_ENV['DEMO_LOGIN_FILE'] = $path;
        $_SERVER['DEMO_LOGIN_FILE'] = $path;

        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoShopSeeder::class);
        $this->seed(DemoShopSeeder::class);

        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        app(TenantManager::class)->set($harbor);

        $this->assertSame(1, WaiverVersion::query()->count());
        $this->assertSame(3, AddOn::query()->count());
        $this->assertSame(1, ServiceDuration::query()->where('duration_minutes', 1440)->count());
        $this->assertSame(3, CancellationPolicy::query()->count());
        $this->assertSame(600, LocationTaxRule::query()->sole()->percent_bps);
        $checkout = RentalCheckout::query()->whereNull('returned_at')->sole();
        $this->assertTrue($checkout->due_at->isFuture());

        $checkout->forceFill(['due_at' => now()->subHour()])->save();
        app(TenantManager::class)->forget();
        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($harbor);
        $this->assertSame(1, RentalCheckout::query()->whereNull('returned_at')->count());
        $this->assertTrue(RentalCheckout::query()->sole()->due_at->isFuture(), 'A rerun rolls the demo rental forward.');

        $inland = Tenant::query()->where('slug', 'inland-demo')->firstOrFail();
        app(TenantManager::class)->set($inland);
        $this->assertSame(0, WaiverVersion::query()->count());
        $this->assertSame(0, AddOn::query()->count());
        $this->assertSame(0, RentalCheckout::query()->count());
    }

    public function test_harbor_checkout_charges_the_sample_tax_on_every_service_location(): void
    {
        $this->seedDemo();
        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        app(TenantManager::class)->set($harbor);
        $other = Location::query()->create([
            'tenant_id' => $harbor->id,
            'name' => 'Harbor Point Marina',
            'timezone' => 'America/New_York',
            'status' => 'active',
        ]);
        // The earlier seeder put the rate on a location found by name, which need not be where the services book.
        LocationTaxRule::query()->update(['location_id' => $other->id]);
        app(TenantManager::class)->forget();

        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($harbor);
        $this->assertSame(2, LocationTaxRule::query()->count(), 'Each location a service books at gets the sample rate once.');
        $kayak = Service::query()->where('name', 'Single kayak rental')->firstOrFail();
        $this->assertSame(1, LocationTaxRule::query()->where('location_id', $kayak->location_id)->count());
        $this->assertTrue(Location::query()->findOrFail($kayak->location_id)->booking_fee_taxable);
        $dayId = ServiceDuration::query()->where('service_id', $kayak->id)->where('duration_minutes', 1440)->value('id');
        app(TenantManager::class)->forget();

        $home = (string) $this->get('http://harbor-demo.reserveworx.test/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#href="([^"]+/services/'.$kayak->id.')"#i', $home, $link));
        $held = $this->post(html_entity_decode($link[1]), [
            'date' => CarbonImmutable::now('America/New_York')->addDays(2)->toDateString(),
            'start' => '10:00',
            'duration_id' => $dayId,
            'participants' => 1,
            'customer_name' => 'Tax Check',
            'customer_email' => 'tax@example.com',
            'idempotency_key' => 'demo-tax-check',
        ])->assertRedirect();

        $this->get((string) $held->headers->get('Location'))->assertOk()
            ->assertSeeInOrder(['Subtotal', '$95.00', 'Booking fee', '$2.85', 'Sample KY sales tax', '$5.87', 'Total', '$103.72']);
    }

    public function test_the_demo_rental_booking_covers_the_same_hours_as_its_checkout(): void
    {
        $this->seedDemo();
        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        app(TenantManager::class)->set($harbor);
        $checkout = RentalCheckout::query()->whereNull('returned_at')->sole();
        $booking = Booking::query()->findOrFail($checkout->booking_id);
        $this->assertSame('demo-harbor-demo-checkout', $booking->idempotency_key);
        $this->assertTrue($booking->starts_at->equalTo($checkout->checked_out_at));
        $this->assertTrue($booking->ends_at->equalTo($checkout->due_at));
        $this->assertTrue($booking->starts_at->isPast());
        $this->assertTrue($checkout->due_at->isFuture());

        // An older run booked tomorrow 9am and left the checkout due today.
        $booking->forceFill([
            'starts_at' => CarbonImmutable::now('America/New_York')->startOfDay()->addDay()->setTime(9, 0)->utc(),
            'ends_at' => CarbonImmutable::now('America/New_York')->startOfDay()->addDay()->setTime(13, 0)->utc(),
        ])->save();
        app(TenantManager::class)->forget();

        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($harbor);
        $checkout = RentalCheckout::query()->whereNull('returned_at')->sole();
        $booking = Booking::query()->findOrFail($checkout->booking_id);
        $this->assertTrue($booking->ends_at->equalTo($checkout->due_at), 'A rerun lines the booking up with the checkout.');
        $this->assertTrue($booking->starts_at->isPast());
        $this->assertSame(1, Booking::query()->where('idempotency_key', 'demo-harbor-demo-checkout')->count());
    }

    public function test_harbor_demo_keeps_one_confirmed_two_day_rental_ahead_of_today(): void
    {
        $this->seedDemo();
        $this->seed(DemoShopSeeder::class);

        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        app(TenantManager::class)->set($harbor);
        $booking = Booking::query()->where('idempotency_key', 'demo-harbor-demo-multiday')->firstOrFail();
        $service = Service::query()->where('name', 'Single kayak rental')->firstOrFail();
        $dayPrice = PriceRule::query()->where('service_id', $service->id)->where('duration_minutes', 1440)->firstOrFail();
        $twoDayPrice = PriceRule::query()->where('service_id', $service->id)->where('duration_minutes', 2880)->firstOrFail();
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $payload = $snapshot->payload;
        $lead = CarbonImmutable::now()->utc()->addDays(7);

        $customer = $booking->customer;
        $this->assertNotNull($customer);
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame($service->id, $booking->service_id);
        $this->assertTrue($booking->starts_at->greaterThanOrEqualTo($lead));
        $this->assertSame(2880 * 60, $booking->ends_at->utc()->getTimestamp() - $booking->starts_at->utc()->getTimestamp());
        $this->assertSame('Avery Cole', $customer->name);
        $this->assertSame('avery.cole@harbor-demo.test', $customer->email);
        $this->assertSame($dayPrice->amount_cents, $twoDayPrice->amount_cents);
        $this->assertSame($dayPrice->amount_cents, $payload['services_cents']);
        $this->assertSame(1, ServiceDuration::query()->where('duration_minutes', 2880)->count());
        $this->assertSame(1, Booking::query()->where('idempotency_key', 'demo-harbor-demo-multiday')->count());
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Refund::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, PlatformFee::query()->where('booking_id', $booking->id)->count());

        $owner = User::query()->where('email', 'harbor-owner@demo.reserveworx.test')->firstOrFail();
        app(TenantManager::class)->forget();
        $this->actingAs($owner);
        $page = $this->get('http://harbor-demo.reserveworx.test/staff/bookings/'.$booking->id)->assertOk();
        $page->assertSee('Guest manage link')->assertSee('Avery Cole');
        $this->assertSame(1, preg_match('/data-guest-manage-link="1" value="([^"]+)"/', (string) $page->getContent(), $link));
        $manage = html_entity_decode($link[1]);

        $this->get($manage)
            ->assertOk()
            ->assertSee('Length: 2 days')
            ->assertSee('The price stays the same.')
            ->assertDontSee('Duration in minutes');

        app(TenantManager::class)->set($harbor);
        $booking->forceFill([
            'starts_at' => CarbonImmutable::now('America/New_York')->addDay()->setTime(10, 0)->utc(),
            'ends_at' => CarbonImmutable::now('America/New_York')->addDay()->setTime(10, 0)->addMinutes(2880)->utc(),
        ])->save();
        app(TenantManager::class)->forget();

        $this->seed(DemoShopSeeder::class);
        app(TenantManager::class)->set($harbor);
        $rolled = Booking::query()->where('idempotency_key', 'demo-harbor-demo-multiday')->firstOrFail();
        $this->assertSame($booking->id, $rolled->id);
        $this->assertSame(1, Booking::query()->where('idempotency_key', 'demo-harbor-demo-multiday')->count());
        $this->assertTrue($rolled->starts_at->greaterThanOrEqualTo($lead));
        $this->assertSame(2880 * 60, $rolled->ends_at->utc()->getTimestamp() - $rolled->starts_at->utc()->getTimestamp());
        $this->assertSame($payload, PricingSnapshot::query()->where('booking_id', $rolled->id)->firstOrFail()->payload);
        $this->assertSame(0, Payment::query()->where('booking_id', $rolled->id)->count());
        $this->assertSame(0, Refund::query()->where('booking_id', $rolled->id)->count());
        $this->assertSame(0, PlatformFee::query()->where('booking_id', $rolled->id)->count());
        $this->assertSame(1, PriceRule::query()->where('duration_minutes', 2880)->count());

        $inland = Tenant::query()->where('slug', 'inland-demo')->firstOrFail();
        app(TenantManager::class)->set($inland);
        $this->assertSame(0, Booking::query()->where('idempotency_key', 'demo-harbor-demo-multiday')->count());
        $this->assertSame(0, ServiceDuration::query()->where('duration_minutes', 2880)->count());
    }

    private function seedDemo(): void
    {
        $path = sys_get_temp_dir().'/reserveworx-demo-logins-'.bin2hex(random_bytes(4)).'.txt';
        putenv('DEMO_LOGIN_FILE='.$path);
        $_ENV['DEMO_LOGIN_FILE'] = $path;
        $_SERVER['DEMO_LOGIN_FILE'] = $path;

        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoShopSeeder::class);
    }
}
