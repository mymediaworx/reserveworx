<?php

namespace Tests\Feature\Seeders;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
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
        $this->assertSame(['Paddleboard rental', 'Single kayak rental', 'Sunset paddle tour', 'Tandem kayak rental'], Service::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(14, Departure::query()->count());
        $this->assertGreaterThan(0, Booking::query()->count());

        $inland = Tenant::query()->where('slug', 'inland-demo')->firstOrFail();
        app(TenantManager::class)->set($inland);
        $this->assertSame(['Canoe float'], Service::query()->pluck('name')->all());

        @unlink($path);
    }
}
