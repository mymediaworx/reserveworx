<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Services\CheckIn\BalanceDue;
use App\Services\CheckIn\CheckInLink;
use App\Services\Notifications\BookingEvents;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicConfirmationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    public function test_check_in_links_use_the_real_primary_host_in_production(): void
    {
        config(['app.url' => 'https://app.reserveworx.com']);
        $this->app['env'] = 'production';
        $workspace = $this->workspace('harbor-demo.reserveworx.test', tenantAttributes: [
            'name' => 'Harbor Demo',
            'slug' => 'harbor-demo',
        ]);
        Domain::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'hostname' => 'harbor.reserveworx.com',
            'type' => 'subdomain',
            'is_primary' => false,
            'verified_at' => now(),
        ]);
        $booking = $this->booking($workspace, 'guest@example.com');
        $this->useTenant($workspace['tenant']);
        $expected = app(CheckInLink::class)->url($booking);

        $this->assertIsString($expected);
        $this->assertStringStartsWith('https://harbor.reserveworx.com/staff/check-in/'.$booking->id, $expected);
        $this->assertStringNotContainsString('reserveworx.test', $expected);
        $this->assertStringNotContainsString('app.reserveworx.com', $expected);

        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $page = $this->get($this->signedUrl('widget.confirmation', 'harbor-demo.reserveworx.test', $booking->id, [
            'token' => $token,
        ]));
        $page->assertOk()
            ->assertSee('https://harbor.reserveworx.com/staff/check-in/'.$booking->id, false)
            ->assertDontSee('harbor-demo.reserveworx.test/staff/check-in', false)
            ->assertDontSee('app.reserveworx.com', false);

        $this->useTenant($workspace['tenant']);
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['booking.confirmed' => true];
        $setting->save();
        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);

        Notification::assertSentOnDemand(
            BookingConfirmedNotification::class,
            function (BookingConfirmedNotification $notification, array $channels, object $notifiable) use ($expected): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $this->assertSame($expected, $notification->checkInUrl);
                $this->assertStringNotContainsString('reserveworx.test', $notification->checkInUrl);
                $this->assertStringNotContainsString('app.reserveworx.com', $notification->checkInUrl);

                return $channels === ['mail'];
            },
        );
    }

    public function test_a_seeded_test_host_is_not_used_when_it_is_the_only_domain_in_production(): void
    {
        config(['app.url' => 'https://app.reserveworx.com']);
        $this->app['env'] = 'production';
        $workspace = $this->workspace('harbor-demo.reserveworx.test');
        $booking = $this->booking($workspace, 'guest@example.com');
        $this->useTenant($workspace['tenant']);

        $this->assertNull(app(CheckInLink::class)->url($booking));

        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $this->get($this->signedUrl('widget.confirmation', 'harbor-demo.reserveworx.test', $booking->id, [
            'token' => $token,
        ]))
            ->assertOk()
            ->assertDontSee('Check-in code')
            ->assertDontSee('app.reserveworx.com')
            ->assertDontSee('reserveworx.test/staff/check-in');
    }

    public function test_pay_at_pickup_shows_the_balance_instead_of_a_raw_payment_status(): void
    {
        $workspace = $this->workspace('dock.test', tenantAttributes: ['name' => 'Harbor Demo']);
        $booking = $this->booking($workspace, 'guest@example.com', PaymentStatus::OfflinePaid);
        $this->useTenant($workspace['tenant']);
        $this->price($booking, 2_266, 66);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'idempotency_key' => 'widget-pickup-'.$booking->id,
            'payload_hash' => str_repeat('cd', 32),
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 2_200,
            'fee_cents' => 0,
        ]);

        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $confirmation = $this->signedUrl('widget.confirmation', 'dock.test', $booking->id, ['token' => $token]);
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Status: Confirmed')
            ->assertSee('Balance due at pickup: $22.00')
            ->assertSee('Your booking is confirmed.')
            ->assertDontSee('offline_paid')
            ->assertDontSee('due_at_pickup');

        $this->get($this->signedUrl('widget.manage', 'dock.test', $booking->id, ['token' => $token]))
            ->assertOk()
            ->assertSee('Balance due at pickup: $22.00')
            ->assertDontSee('offline_paid');

        $this->useTenant($workspace['tenant']);
        $this->assertSame(2_200, app(BalanceDue::class)->forBooking($booking)['cents']);

        $migration = require base_path('database/migrations/2026_10_25_110000_correct_pay_at_pickup_commitments.php');
        $migration->up();
        $fresh = Booking::query()->findOrFail($booking->id);
        $this->assertSame(PaymentStatus::DueAtPickup, $fresh->payment_status);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(2_200, app(BalanceDue::class)->forBooking($fresh)['cents']);

        $collected = $this->booking($workspace, 'paid@example.com', PaymentStatus::OfflinePaid, 'Paid Guest');
        $this->price($collected, 2_266, 66);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $collected->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 2_200,
            'fee_cents' => 0,
        ]);
        $this->get($this->signedUrl('widget.confirmation', 'dock.test', $collected->id, ['token' => $token]))
            ->assertOk()
            ->assertSee('Status: Confirmed. Paid.')
            ->assertDontSee('offline_paid')
            ->assertDontSee('Balance due at pickup');
    }

    public function test_demo_seed_uses_harbor_demo_and_the_real_primary_domain(): void
    {
        $path = sys_get_temp_dir().'/reserveworx-demo-logins-'.bin2hex(random_bytes(4)).'.txt';
        putenv('DEMO_LOGIN_FILE='.$path);
        $_ENV['DEMO_LOGIN_FILE'] = $path;
        $_SERVER['DEMO_LOGIN_FILE'] = $path;

        $this->seed(DatabaseSeeder::class);

        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        $seeded = Domain::withoutGlobalScope(TenantScope::class)
            ->where('hostname', 'harbor-demo.reserveworx.test')
            ->firstOrFail();
        $this->assertTrue($seeded->is_primary);

        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $harbor->id)
            ->update(['display_name' => 'Yak Shack Watersports']);
        Domain::factory()->create([
            'tenant_id' => $harbor->id,
            'hostname' => 'harbor.reserveworx.com',
            'type' => 'subdomain',
            'is_primary' => false,
            'verified_at' => now(),
        ]);
        $other = Tenant::factory()->create(['slug' => 'real-marina', 'name' => 'Real Marina']);
        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $other->id)
            ->update(['display_name' => 'Custom Marina']);

        $this->app['env'] = 'production';
        $this->artisan('db:seed', [
            '--class' => DemoShopSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(
            'Harbor Demo',
            TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $harbor->id)->value('display_name'),
        );
        $this->assertSame(
            'Custom Marina',
            TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $other->id)->value('display_name'),
        );
        $this->assertFalse($seeded->fresh()->is_primary);
        $this->assertTrue(
            Domain::withoutGlobalScope(TenantScope::class)->where('hostname', 'harbor.reserveworx.com')->firstOrFail()->is_primary,
        );

        $home = $this->get('http://harbor.reserveworx.com/')
            ->assertOk()
            ->assertSee('<h1>Harbor Demo</h1>', false)
            ->assertSee('Harbor Demo bookings', false)
            ->assertDontSee('Yak Shack Watersports');
        $this->assertSame(1, preg_match('#/book/(hosted_[a-f0-9]{64})/#', (string) $home->getContent(), $token));
        $this->get('http://harbor.reserveworx.com/book/'.$token[1].'/lookup')
            ->assertOk()
            ->assertSee('>Harbor Demo<', false)
            ->assertDontSee('Yak Shack Watersports');

        @unlink($path);
        putenv('DEMO_LOGIN_FILE');
        unset($_ENV['DEMO_LOGIN_FILE'], $_SERVER['DEMO_LOGIN_FILE']);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function booking(array $workspace, string $email, PaymentStatus $payment = PaymentStatus::Unpaid, string $name = 'Ada North'): Booking
    {
        $this->useTenant($workspace['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Paddleboard rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $name,
            'email' => $email,
        ]);
        $start = CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC');

        return Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => $payment,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email.$name),
        ]);
    }

    private function price(Booking $booking, int $total, int $feeLine): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => [
                'customer_total_cents' => $total,
                'customer_fee_line_cents' => $feeLine,
            ],
        ]);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function signedUrl(string $name, string $host, string $bookingId, array $parameters = []): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::signedRoute($name, array_merge(['booking' => $bookingId], $parameters));
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
