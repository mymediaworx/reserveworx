<?php

namespace Tests\Feature\Seeders;

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\PriceRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
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
}
