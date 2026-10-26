<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Notifications\CustomerSignInLink;
use App\Services\Customers\CustomerAccounts;
use App\Support\BookingReference;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerAccountPageTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private const NEXT_PASSWORD = 'fresh-harbor-paddle';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_guest_checkout_links_to_an_optional_account_and_still_asks_for_a_name(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => 'rental',
        ]);

        $home = $this->get($this->tenantUrl('dock.test', '/'));
        $home->assertOk()
            ->assertSee('Choose a service')
            ->assertSee('Open an account')
            ->assertSee('Booking does not require one.')
            ->assertSee('href="http://dock.test/account"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener"', false)
            ->assertDontSee('type="password"', false);
        $this->assertStringNotContainsString('laravel_session', (string) $home->headers->get('set-cookie'));

        $plain = $this->hostedToken((string) $home->getContent());
        $this->get($this->tenantUrl('dock.test', '/book/'.$plain.'/services/'.$service->id))
            ->assertOk()
            ->assertSee('Your name')
            ->assertSee('name="customer_name"', false)
            ->assertSee('name="customer_email"', false)
            ->assertDontSee('type="password"', false)
            ->assertDontSee('Open an account');
    }

    public function test_a_staff_session_does_not_list_the_customer_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $booking = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'ada@example.com', '2026-12-01 15:00:00', '2026-12-01 16:00:00');
        $account = CustomerAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'email' => 'ada@example.com',
            'name' => 'Ada Kayak',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();
        Customer::withoutGlobalScope(TenantScope::class)
            ->whereKey($booking->customer_id)
            ->update(['customer_account_id' => $account->id]);

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('dock.test', '/account'))
            ->assertOk()
            ->assertSee('Email me a sign-in link')
            ->assertDontSee('Signed in as')
            ->assertDontSee($booking->id)
            ->assertDontSee(BookingReference::short($booking->id));
    }

    public function test_opening_an_account_lists_that_emails_bookings_and_receipt_without_changing_the_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        $this->clearCustomerAuthLimit('ada@example.com');
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('other.test', 'owner', ['name' => 'Other Dock']);
        $past = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'ada@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $upcoming = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'ada@example.com', '2026-12-01 15:00:00', '2026-12-01 16:00:00');
        $stranger = $this->booking($workspace['tenant']->id, 'Paddle', 'Bea Paddle', 'bea@example.com', '2026-12-02 15:00:00', '2026-12-02 16:00:00');
        $foreign = $this->booking($other['tenant']->id, 'Canoe', 'Ada Kayak', 'ada@example.com', '2026-12-03 15:00:00', '2026-12-03 16:00:00');
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $upcoming->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1',
            'payload' => [
                'services_cents' => 2500,
                'discount_cents' => 0,
                'add_ons_cents' => 0,
                'customer_total_cents' => 2500,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $upcoming->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 2500,
            'fee_cents' => 0,
        ]);

        Notification::fake();
        $accountUrl = $this->tenantUrl('dock.test', '/account');
        $this->post($accountUrl, [
            'email' => 'Ada@example.com',
            'name' => 'Ada Kayak',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/account');
        $this->get($accountUrl)
            ->assertOk()
            ->assertSee(CustomerAccounts::REQUEST_STATUS)
            ->assertSee('Booking does not require an account.');

        $this->post($accountUrl, [
            'email' => 'ada@example.com',
        ])->assertRedirect('/account');
        $this->get($accountUrl)->assertSee(CustomerAccounts::REQUEST_STATUS);
        $this->assertSame(1, CustomerAccount::withoutGlobalScope(TenantScope::class)->count());

        $this->from($accountUrl)
            ->post($this->tenantUrl('dock.test', '/account/login'), [
                'email' => 'ada@example.com',
                'password' => self::PASSWORD,
            ])
            ->assertSessionHasErrors(['email' => CustomerAccounts::INVALID_CREDENTIALS]);
        $this->get($accountUrl)
            ->assertSee(CustomerAccounts::INVALID_CREDENTIALS)
            ->assertSee('Email me a sign-in link')
            ->assertDontSee($upcoming->id);

        $url = $this->latestLink();
        $this->assertStringStartsWith('http://dock.test/account/sign-in/', $url);
        $otherUrl = 'http://other.test'.(string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
        $this->get($otherUrl)
            ->assertOk()
            ->assertSee(CustomerAccounts::INVALID_LINK)
            ->assertDontSee('Signed in as');
        $this->assertNull($this->account('ada@example.com')->email_verified_at);

        $this->get($url)->assertRedirect('/account');
        $this->assertNotNull($this->account('ada@example.com')->email_verified_at);
        $accountId = $this->account('ada@example.com')->id;
        $this->assertSame($accountId, $this->linkedAccountId($past->customer_id));
        $this->assertSame($accountId, $this->linkedAccountId($upcoming->customer_id));
        $this->assertNull($this->linkedAccountId($stranger->customer_id));
        $this->assertNull($this->linkedAccountId($foreign->customer_id));

        $page = $this->get($accountUrl);
        $page->assertOk()
            ->assertSee('Signed in as ada@example.com.')
            ->assertSee(BookingReference::short($upcoming->id))
            ->assertSee(BookingReference::short($past->id))
            ->assertSee('data-booking="'.$upcoming->id.'"', false)
            ->assertSee('data-when="upcoming"', false)
            ->assertSee('data-booking="'.$past->id.'"', false)
            ->assertSee('data-when="past"', false)
            ->assertSee('Tue, Dec 1, 2026 10:00 AM')
            ->assertSee('Tue, Jun 16, 2026 11:00 AM')
            ->assertSee('$25.00')
            ->assertSee('data-receipt="'.$upcoming->id.'"', false)
            ->assertDontSee('Canoe')
            ->assertDontSee('Paddle')
            ->assertDontSee($stranger->id)
            ->assertDontSee($foreign->id);

        $this->assertSame(2500, Payment::withoutGlobalScope(TenantScope::class)->whereKey($payment->id)->value('amount_cents'));
        $this->assertSame(1, Payment::withoutGlobalScope(TenantScope::class)->count());
        $snapshot = PricingSnapshot::withoutGlobalScope(TenantScope::class)->where('booking_id', $upcoming->id)->firstOrFail();
        $this->assertSame(2500, $snapshot->payload['customer_total_cents'] ?? null);

        $this->get($this->tenantUrl('other.test', '/account'))
            ->assertOk()
            ->assertSee('Email me a sign-in link')
            ->assertDontSee('Signed in as ada@example.com.')
            ->assertDontSee($upcoming->id)
            ->assertDontSee('Canoe');

        $this->get($this->tenantUrl('dock.test', '/staff'))->assertRedirect('/staff/login');
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))->assertUnauthorized();

        $this->clearCustomerAuthLimit('ada@example.com');
        $this->get($url)
            ->assertOk()
            ->assertSee(CustomerAccounts::INVALID_LINK)
            ->assertDontSee('Signed in as');

        $this->post($this->tenantUrl('dock.test', '/account/logout'))->assertRedirect('/account');
        $this->get($accountUrl)->assertSee('Email me a sign-in link')->assertDontSee($upcoming->id);

        $this->clearCustomerAuthLimit('ada@example.com');
        $this->post($this->tenantUrl('dock.test', '/account/login'), [
            'email' => 'ADA@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect('/account');
        $this->get($accountUrl)->assertSee('Signed in as ada@example.com.');

        $this->post($this->tenantUrl('dock.test', '/account/password'), [
            'password' => self::NEXT_PASSWORD,
            'password_confirmation' => self::NEXT_PASSWORD,
        ])->assertRedirect('/account')->assertSessionHas('status', 'Password saved.');

        $this->post($this->tenantUrl('dock.test', '/account/logout'))->assertRedirect('/account');
        $this->clearCustomerAuthLimit('ada@example.com');
        $this->from($accountUrl)
            ->post($this->tenantUrl('dock.test', '/account/login'), [
                'email' => 'ada@example.com',
                'password' => self::PASSWORD,
            ])
            ->assertSessionHasErrors(['email' => CustomerAccounts::INVALID_CREDENTIALS]);
        $this->post($this->tenantUrl('dock.test', '/account/login'), [
            'email' => 'ada@example.com',
            'password' => self::NEXT_PASSWORD,
        ])->assertRedirect('/account');
        $this->get($accountUrl)->assertSee('Signed in as ada@example.com.');

        $encoded = (string) json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString(self::NEXT_PASSWORD, $encoded);
    }

    public function test_a_json_sign_in_returns_a_bearer_token_without_signing_in_the_browser(): void
    {
        $this->clearCustomerAuthLimit('ada@example.com');
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        Notification::fake();
        $this->post($this->tenantUrl('dock.test', '/account'), [
            'email' => 'ada@example.com',
        ])->assertRedirect('/account');

        $url = $this->latestLink();
        $signedIn = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('account.email', 'ada@example.com');
        $this->assertIsString($signedIn->json('token'));

        $this->get($this->tenantUrl('dock.test', '/account'))
            ->assertOk()
            ->assertSee('Email me a sign-in link')
            ->assertDontSee('Signed in as');
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))->assertUnauthorized();
        $this->getJson($url)->assertUnprocessable()->assertJsonPath('errors.email.0', CustomerAccounts::INVALID_LINK);
        $this->assertSame($workspace['tenant']->id, $this->account('ada@example.com')->tenant_id);
    }

    public function test_an_expired_link_shows_the_guest_form(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        $this->clearCustomerAuthLimit('ada@example.com');
        $this->workspace('dock.test');
        Notification::fake();
        $this->post($this->tenantUrl('dock.test', '/account'), [
            'email' => 'ada@example.com',
        ])->assertRedirect('/account');

        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC')->addMinutes(61));
        $this->clearCustomerAuthLimit('ada@example.com');
        $this->get($this->latestLink())
            ->assertOk()
            ->assertSee(CustomerAccounts::INVALID_LINK)
            ->assertSee('Email me a sign-in link')
            ->assertDontSee('Signed in as');
        $this->assertNull($this->account('ada@example.com')->email_verified_at);
    }

    public function test_the_account_page_is_not_served_on_the_platform_host(): void
    {
        $this->workspace('dock.test');

        $this->get('http://localhost/account')->assertNotFound();
        $this->get('http://app.reserveworx.com/account')->assertNotFound();
        $this->post('http://localhost/account', ['email' => 'ada@example.com'])->assertNotFound();
    }

    public function test_account_sign_in_attempts_are_rate_limited(): void
    {
        $this->workspace('dock.test');
        $this->clearCustomerAuthLimit('ada@example.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->tenantUrl('dock.test', '/account'), [
                'email' => 'ada@example.com',
            ])->assertRedirect('/account');
        }

        $this->post($this->tenantUrl('dock.test', '/account'), [
            'email' => 'ada@example.com',
        ])->assertStatus(429);
        $this->assertSame(1, CustomerAccount::withoutGlobalScope(TenantScope::class)->count());
    }

    private function clearCustomerAuthLimit(string $email): void
    {
        RateLimiter::clear(md5('customer-auth'.strtolower($email).'|127.0.0.1'));
        RateLimiter::clear(md5('customer-auth|127.0.0.1'));
        RateLimiter::clear(md5('customer-authip|127.0.0.1'));
    }

    private function latestLink(): string
    {
        $account = $this->account('ada@example.com');
        $url = '';
        Notification::assertSentTo($account, CustomerSignInLink::class, function (CustomerSignInLink $notification) use (&$url): bool {
            $url = $notification->url;

            return str_contains($notification->url, '/account/sign-in/');
        });

        return $url;
    }

    private function account(string $email): CustomerAccount
    {
        return CustomerAccount::withoutGlobalScope(TenantScope::class)->where('email', strtolower($email))->firstOrFail();
    }

    private function linkedAccountId(?string $customerId): ?string
    {
        $id = Customer::withoutGlobalScope(TenantScope::class)->whereKey($customerId)->value('customer_account_id');

        return is_string($id) ? $id : null;
    }

    private function hostedToken(string $html): string
    {
        $this->assertSame(1, preg_match('#/book/(hosted_[a-f0-9]{64})/#', $html, $matches));

        return $matches[1];
    }

    private function booking(string $tenantId, string $serviceName, string $name, string $email, string $startsAt, string $endsAt): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Harbor',
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $serviceName,
            'kind' => 'rental',
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower($email),
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 2,
            'payload_hash' => hash('sha256', $customer->id.$startsAt),
        ]);
    }
}
