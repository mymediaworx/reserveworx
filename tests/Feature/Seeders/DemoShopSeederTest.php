<?php

namespace Tests\Feature\Seeders;

use App\Models\AddOn;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\PriceRule;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
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
