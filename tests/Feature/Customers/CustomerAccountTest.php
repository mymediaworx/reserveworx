<?php

namespace Tests\Feature\Customers;

use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerSignInToken;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CustomerSignInLink;
use App\Services\Customers\CustomerAccounts;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function tearDown(): void
    {
        config(['session.encrypt' => false]);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_guest_booking_links_when_the_same_email_verifies_an_account(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('other.test', 'owner', ['name' => 'Other Dock']);
        $past = $this->booking($workspace['tenant']->id, 'Ada Kayak', 'Ada@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $upcoming = $this->booking($workspace['tenant']->id, 'Ada Kayak', 'ada@example.com', '2026-12-01 15:00:00', '2026-12-01 16:00:00');
        $stranger = $this->booking($workspace['tenant']->id, 'Bea Paddle', 'bea@example.com', '2026-12-02 15:00:00', '2026-12-02 16:00:00');
        $foreign = $this->booking($other['tenant']->id, 'Ada Other', 'ada@example.com', '2026-12-03 15:00:00', '2026-12-03 16:00:00');

        $url = $this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com');
        $this->assertStringStartsWith('http://dock.test/account/sign-in/', $url);
        $this->assertStringNotContainsString('app.reserveworx.com', $url);

        $account = $this->account('ada@example.com');
        $this->assertSame($workspace['tenant']->id, $account->tenant_id);
        $this->assertNull($account->email_verified_at);
        $this->assertNull($account->password);
        $this->assertNull($account->name);
        $this->assertNull($this->linkedAccountId($past->customer_id));

        $plain = $this->tokenFrom($url);
        $this->assertDatabaseMissing('customer_sign_in_tokens', ['token_hash' => $plain]);
        $this->assertDatabaseHas('customer_sign_in_tokens', [
            'token_hash' => hash('sha256', $plain),
            'tenant_id' => $workspace['tenant']->id,
        ]);

        $signedIn = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('account.email', 'ada@example.com')
            ->assertJsonPath('account.name', 'Ada Kayak');

        $token = $signedIn->json('token');
        $this->assertIsString($token);
        $this->getJson($url)->assertUnprocessable()->assertJsonPath('errors.email.0', CustomerAccounts::INVALID_LINK);

        $this->assertNotNull($account->fresh()->email_verified_at);
        $this->assertSame($account->id, $this->linkedAccountId($past->customer_id));
        $this->assertSame($account->id, $this->linkedAccountId($upcoming->customer_id));
        $this->assertNull($this->linkedAccountId($stranger->customer_id));
        $this->assertNull($this->linkedAccountId($foreign->customer_id));

        $listed = $this->withToken($token)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))->assertOk();
        $this->assertSame([$upcoming->id], collect($listed->json('upcoming'))->pluck('id')->all());
        $this->assertSame([$past->id], collect($listed->json('past'))->pluck('id')->all());
        $this->assertSame('Kayak', $listed->json('upcoming.0.service_name'));

        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings?when=upcoming'))
            ->assertOk()
            ->assertJsonCount(1, 'upcoming')
            ->assertJsonCount(0, 'past');

        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$past->id))
            ->assertOk()
            ->assertJsonPath('data.when', 'past')
            ->assertJsonPath('data.reference', $past->id);

        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$stranger->id))
            ->assertNotFound();
        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$foreign->id))
            ->assertNotFound();
        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$foreign->id.'/receipt'))
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('other.test', '/api/v1/customer/bookings'))
            ->assertUnauthorized();
        $this->withToken($token)
            ->getJson($this->tenantUrl('other.test', '/api/v1/customer/me'))
            ->assertUnauthorized();

        $encoded = (string) json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString($plain, $encoded);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
    }

    public function test_a_later_guest_booking_links_without_another_sign_in(): void
    {
        [$workspace, $service] = $this->rental();
        $url = $this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com', 'Ada Kayak');
        $token = $this->getJson($url)->assertOk()->json('token');
        $this->assertIsString($token);

        $this->app['auth']->forgetGuards();
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-12-04T15:00:00Z',
            'ends_at' => '2026-12-04T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'guest-after-account',
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'ada@example.com',
        ])->assertCreated();

        $account = $this->account('ada@example.com');
        app(TenantManager::class)->set($workspace['tenant']);
        $customer = Customer::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame($account->id, $customer->customer_account_id);
        app(TenantManager::class)->forget();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$created->json('data.id')))
            ->assertOk()
            ->assertJsonPath('data.service_name', 'Hourly boat');
    }

    public function test_password_sign_in_waits_for_the_emailed_link(): void
    {
        $workspace = $this->workspace('dock.test');
        $url = $this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com', null, self::PASSWORD);
        $account = $this->account('ada@example.com');
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $account->password));

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/session'), [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', CustomerAccounts::INVALID_CREDENTIALS);

        $this->getJson($url)->assertOk();
        $signedIn = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/session'), [
            'email' => 'ADA@example.com',
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('account.email', 'ada@example.com');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/accounts'), [
            'email' => 'ada@example.com',
            'password' => 'another-harbor-paddle',
            'password_confirmation' => 'another-harbor-paddle',
        ])->assertOk()->assertJsonPath('message', CustomerAccounts::REQUEST_STATUS);
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $account->fresh()->password));

        $token = $signedIn->json('token');
        $this->assertIsString($token);
        $this->withToken($token)->putJson($this->tenantUrl('dock.test', '/api/v1/customer/password'), [
            'password' => 'fresh-harbor-paddle',
            'password_confirmation' => 'fresh-harbor-paddle',
        ])->assertOk();
        $this->assertTrue(Hash::check('fresh-harbor-paddle', (string) $account->fresh()->password));
        RateLimiter::clear(md5('customer-authada@example.com|127.0.0.1'));
        RateLimiter::clear(md5('customer-authip|127.0.0.1'));

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/session'), [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/session'), [
            'email' => 'ada@example.com',
            'password' => 'fresh-harbor-paddle',
        ])->assertOk();

        $this->withToken($token)->postJson($this->tenantUrl('dock.test', '/api/v1/customer/logout'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertUnauthorized();

        $encoded = (string) json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString('fresh-harbor-paddle', $encoded);
        $this->assertStringNotContainsString('another-harbor-paddle', $encoded);
    }

    public function test_an_unknown_email_gets_the_same_reply_and_no_account(): void
    {
        $this->workspace('dock.test');
        Notification::fake();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/sign-in-links'), [
            'email' => 'nobody@example.com',
        ])->assertOk()->assertJsonPath('message', CustomerAccounts::REQUEST_STATUS);

        Notification::assertNothingSent();
        $this->assertSame(0, CustomerAccount::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_link_for_one_workspace_does_not_sign_in_another(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha']);
        $this->workspace('beta.test', 'owner', ['name' => 'Beta']);
        $url = $this->captureLink($alpha['tenant']->id, 'http://alpha.test', 'ada@example.com');
        $betaUrl = 'http://beta.test'.(string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);

        $this->getJson($betaUrl)->assertUnprocessable()->assertJsonPath('errors.email.0', CustomerAccounts::INVALID_LINK);
        $this->getJson($url)->assertOk();
        $this->assertSame(1, CustomerAccount::withoutGlobalScope(TenantScope::class)->whereNotNull('email_verified_at')->count());
        $this->assertSame(0, CustomerAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', '!=', $alpha['tenant']->id)->count());
    }

    public function test_a_sign_in_link_expires_and_a_mismatched_email_does_not_spend_it(): void
    {
        $workspace = $this->workspace('dock.test');
        $url = $this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->getJson('http://dock.test'.$path.'?email=other%40example.com')
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', CustomerAccounts::INVALID_LINK);

        $this->travel(61)->minutes();
        $this->getJson($url)->assertUnprocessable();
        $this->travelBack();

        $this->assertNull($this->account('ada@example.com')->email_verified_at);
    }

    public function test_customer_and_staff_tokens_stay_on_their_own_routes(): void
    {
        $workspace = $this->workspace('dock.test');
        $booking = $this->booking($workspace['tenant']->id, 'Ada Kayak', 'ada@example.com', '2026-12-01 15:00:00', '2026-12-01 16:00:00');
        $url = $this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com');
        $customerToken = $this->getJson($url)->assertOk()->json('token');
        $this->assertIsString($customerToken);

        $staff = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ]);
        $workspace['membership']->forceFill(['user_id' => $staff->id])->save();
        $staffToken = $this->postJson('http://localhost/api/v1/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ])->assertOk()->json('token');
        $this->assertIsString($staffToken);

        $this->app['auth']->forgetGuards();
        $this->withToken($customerToken)->getJson($this->tenantUrl('dock.test', '/api/v1/bookings'))->assertUnauthorized();
        $this->withToken($customerToken)->getJson($this->tenantUrl('dock.test', '/api/v1/customers'))->assertUnauthorized();
        $this->withToken($customerToken)->getJson('http://localhost/api/v1/me')->assertUnauthorized();
        $this->withToken($customerToken)->postJson('http://localhost/api/v1/logout')->assertUnauthorized();
        $this->withToken($customerToken)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$booking->id))
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))->assertUnauthorized();
        $this->withToken($staffToken)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/receipts'))->assertUnauthorized();
        $this->withToken($staffToken)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertUnauthorized();
        $this->withToken($staffToken)->getJson($this->tenantUrl('dock.test', '/api/v1/bookings'))->assertOk();
    }

    public function test_a_receipt_itemizes_the_booking_fee_for_that_customer_only(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        app(TenantManager::class)->set($workspace['tenant']);
        $terms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($workspace['tenant'], FeePayer::CustomerPaid);
        app(TenantManager::class)->forget();
        $this->assertNotNull($terms);

        $booking = $this->booking($workspace['tenant']->id, 'Ada Kayak', 'ada@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $other = $this->booking($workspace['tenant']->id, 'Bea Paddle', 'bea@example.com', '2026-06-17 15:00:00', '2026-06-17 16:00:00');
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1',
            'disclosure_version_id' => $terms->id,
            'payload' => [
                'services_cents' => 10_000,
                'discount_cents' => 0,
                'add_ons_cents' => 0,
                'operator_fees' => [['name' => 'Cleaning', 'total_cents' => 500]],
                'customer_fee_line_cents' => 300,
                'tax_cents' => 700,
                'customer_total_cents' => 11_500,
            ],
        ]);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'checkout',
            'status' => 'paid',
            'amount_cents' => 11_500,
            'fee_cents' => 300,
        ]);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $other->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 99,
            'fee_cents' => 0,
        ]);
        Refund::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'reason' => 'customer_partial_refund',
            'rules_version' => 'spec-1',
            'customer_refund_cents' => 5_500,
            'customer_fee_refund_cents' => 150,
            'application_fee_refund_cents' => 150,
            'refund_subtotal_cents' => 5_000,
            'refund_tax_cents' => 350,
        ]);

        $token = $this->getJson($this->captureLink($workspace['tenant']->id, 'http://dock.test', 'ada@example.com'))->assertOk()->json('token');
        $this->assertIsString($token);

        $receipt = $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$booking->id.'/receipt'))
            ->assertOk()
            ->assertJsonPath('data.subtotal_cents', 10_000)
            ->assertJsonPath('data.operator_fees.0.name', 'Cleaning')
            ->assertJsonPath('data.booking_fee_cents', 300)
            ->assertJsonPath('data.tax_cents', 700)
            ->assertJsonPath('data.total_cents', 11_500)
            ->assertJsonPath('data.paid_cents', 11_500)
            ->assertJsonPath('data.refunded_cents', 5_500)
            ->assertJsonPath('data.refunded_booking_fee_cents', 150)
            ->assertJsonPath('data.payments.0.amount_cents', 11_500);

        $html = $receipt->json('data.html');
        $this->assertIsString($html);
        $this->assertStringContainsString('Booking fee', $html);
        $this->assertStringContainsString('$3.00', $html);
        $this->assertStringContainsString('Cleaning', $html);
        $this->assertMatchesRegularExpression('/Refunded.*\$55\.00.*Booking fee refunded.*\$1\.50/s', $html);
        $this->assertStringNotContainsString('99', $html);

        $list = $this->withToken($token)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/receipts'))->assertOk();
        $this->assertSame([$booking->id], collect($list->json('data'))->pluck('booking_id')->all());

        $this->withToken($token)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings/'.$other->id.'/receipt'))
            ->assertNotFound();
    }

    public function test_customer_sign_in_is_rate_limited_and_refuses_the_platform_host(): void
    {
        $this->workspace('dock.test');
        $this->postJson('http://app.reserveworx.com/api/v1/customer/accounts', [
            'email' => 'ada@example.com',
        ])->assertNotFound();

        RateLimiter::clear(md5('customer-authada@example.com|127.0.0.1'));
        RateLimiter::clear(md5('customer-authip|127.0.0.1'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/accounts'), [
                'email' => 'ada@example.com',
            ])->assertOk();
        }

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/accounts'), [
            'email' => 'ada@example.com',
        ])->assertStatus(429);
    }

    public function test_saving_a_password_ends_other_browsers_and_api_tokens(): void
    {
        $dock = $this->workspace('dock.test', 'owner', ['name' => 'Harbor']);
        $other = $this->workspace('other.test', 'owner', ['name' => 'Inland']);
        $url = $this->captureLink($dock['tenant']->id, 'http://dock.test', 'ada@example.com', 'Ada', self::PASSWORD);
        $this->getJson($url)->assertOk();
        $account = $this->account('ada@example.com');
        $otherUrl = $this->captureLink($other['tenant']->id, 'http://other.test', 'bee@example.com', 'Bee', self::PASSWORD);
        $this->getJson($otherUrl)->assertOk();
        $otherAccount = CustomerAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $other['tenant']->id)
            ->where('email', 'bee@example.com')
            ->firstOrFail();

        $current = $account->createToken('phone')->plainTextToken;
        $elsewhere = $account->createToken('tablet')->plainTextToken;
        $otherToken = $otherAccount->createToken('phone')->plainTextToken;
        $this->assertIsString($current);
        $this->assertIsString($elsewhere);
        $this->assertIsString($otherToken);

        $openLink = $this->signInToken($account, false);
        $usedLink = $this->signInToken($account, true);
        $otherLink = $this->signInToken($otherAccount, false);
        $money = $this->moneyCounts();

        $this->actingAs($account, 'customer_web')
            ->get($this->tenantUrl('dock.test', '/account'))
            ->assertOk()
            ->assertSee('Signed in as ada@example.com.');

        RateLimiter::clear(md5('customer-authada@example.com|127.0.0.1'));
        $this->from($this->tenantUrl('dock.test', '/account'))
            ->post($this->tenantUrl('dock.test', '/account/password'), [
                'password' => 'fresh-harbor-paddle',
                'password_confirmation' => 'not-the-same-pass',
            ])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $account->fresh()->password));

        $currentSession = str_repeat('a', 40);
        $this->storeCustomerSession($currentSession, $account->id);
        $this->storeCustomerSession('other-browser', $account->id);
        $this->storeCustomerSession('other-account-browser', $otherAccount->id);
        $this->storeStaffSession('staff-browser', $dock['user']->id);
        $this->assertDatabaseHas('sessions', ['id' => 'other-browser']);
        $this->assertNotNull(CustomerSignInToken::withoutGlobalScope(TenantScope::class)->find($openLink));

        $this->withCookie((string) config('session.cookie'), $currentSession)
            ->post($this->tenantUrl('dock.test', '/account/password'), [
                'password' => 'fresh-harbor-paddle',
                'password_confirmation' => 'fresh-harbor-paddle',
            ])
            ->assertRedirect('/account')
            ->assertSessionHas('status', 'Password saved.');

        $this->get($this->tenantUrl('dock.test', '/account'))
            ->assertOk()
            ->assertSee('Signed in as ada@example.com.');
        $this->assertDatabaseHas('sessions', ['id' => $currentSession]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'staff-browser']);
        $this->assertNull(CustomerSignInToken::withoutGlobalScope(TenantScope::class)->find($openLink));
        $this->assertNotNull(CustomerSignInToken::withoutGlobalScope(TenantScope::class)->find($usedLink));
        $this->assertNotNull(CustomerSignInToken::withoutGlobalScope(TenantScope::class)->find($otherLink));

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertUnauthorized();
        $this->withToken($elsewhere)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertUnauthorized();
        $this->withToken($otherToken)->getJson($this->tenantUrl('other.test', '/api/v1/customer/me'))->assertOk();

        $kept = $account->fresh()->createToken('kept')->plainTextToken;
        $dropped = $account->fresh()->createToken('dropped')->plainTextToken;
        $this->assertIsString($kept);
        $this->assertIsString($dropped);
        $this->storeCustomerSession('api-other-browser', $account->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($kept)->putJson($this->tenantUrl('dock.test', '/api/v1/customer/password'), [
            'password' => 'second-harbor-paddle',
            'password_confirmation' => 'second-harbor-paddle',
        ])->assertOk();
        $this->withToken($kept)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($dropped)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertUnauthorized();
        $this->withToken($otherToken)->getJson($this->tenantUrl('other.test', '/api/v1/customer/me'))->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'api-other-browser']);
        $this->assertDatabaseMissing('sessions', ['id' => $currentSession]);
        $this->assertDatabaseHas('sessions', ['id' => 'staff-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-browser']);

        $fresh = $account->fresh();
        $this->assertInstanceOf(CustomerAccount::class, $fresh);
        $this->assertTrue(Hash::check('second-harbor-paddle', (string) $fresh->password));
        $this->assertNotSame('', (string) $fresh->remember_token);
        $encoded = (string) json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString('fresh-harbor-paddle', $encoded);
        $this->assertStringNotContainsString('second-harbor-paddle', $encoded);
        $this->assertStringNotContainsString((string) $fresh->remember_token, $encoded);
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_an_encrypted_customer_session_ends_when_the_password_changes(): void
    {
        config(['session.encrypt' => true]);
        $dock = $this->workspace('dock.test', 'owner', ['name' => 'Harbor']);
        $account = CustomerAccount::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $dock['tenant']->id,
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'password' => self::PASSWORD,
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();
        $token = $account->createToken('phone')->plainTextToken;
        $this->assertIsString($token);
        $this->storeCustomerSession('encrypted-browser', $account->id, true);
        $this->storeStaffSession('staff-browser', $dock['user']->id);
        $money = $this->moneyCounts();

        $this->withToken($token)->putJson($this->tenantUrl('dock.test', '/api/v1/customer/password'), [
            'password' => 'fresh-harbor-paddle',
            'password_confirmation' => 'fresh-harbor-paddle',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'encrypted-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'staff-browser']);
        $this->withToken($token)->getJson($this->tenantUrl('dock.test', '/api/v1/customer/me'))->assertOk();
        $this->assertSame($money, $this->moneyCounts());
        config(['session.encrypt' => false]);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->workspace('dock.test');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/accounts'), [
            'email' => 'ada@example.com',
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame(0, CustomerAccount::withoutGlobalScope(TenantScope::class)->count());
    }

    private function storeCustomerSession(string $id, string $accountId, bool $encrypted = false): void
    {
        $json = json_encode([
            Auth::guard('customer_web')->getName() => $accountId,
            '_token' => 'csrf-token',
        ], JSON_THROW_ON_ERROR);
        $this->insertSession($id, base64_encode($encrypted ? Crypt::encrypt($json) : $json), null);
    }

    private function storeStaffSession(string $id, string $userId): void
    {
        $json = json_encode([
            Auth::guard('web')->getName() => $userId,
            '_token' => 'staff-csrf',
        ], JSON_THROW_ON_ERROR);
        $this->insertSession($id, base64_encode($json), $userId);
    }

    private function insertSession(string $id, string $payload, ?string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $payload,
            'last_activity' => time(),
        ]);
    }

    private function signInToken(CustomerAccount $account, bool $consumed): string
    {
        $row = CustomerSignInToken::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $account->tenant_id,
            'customer_account_id' => $account->id,
            'token_hash' => hash('sha256', $account->id.'-'.($consumed ? 'used' : 'open').'-'.str()->random(16)),
            'expires_at' => now()->addHour(),
            'consumed_at' => $consumed ? now() : null,
        ]);

        return $row->id;
    }

    /**
     * @return list<int>
     */
    private function moneyCounts(): array
    {
        return [
            Payment::withoutGlobalScope(TenantScope::class)->count(),
            Refund::withoutGlobalScope(TenantScope::class)->count(),
            PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
            PriceRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function captureLink(string $tenantId, string $origin, string $email, ?string $name = null, ?string $password = null): string
    {
        Notification::fake();
        $payload = ['email' => $email];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if ($password !== null) {
            $payload['password'] = $password;
            $payload['password_confirmation'] = $password;
        }

        $this->postJson($origin.'/api/v1/customer/accounts', $payload)
            ->assertOk()
            ->assertJsonPath('message', CustomerAccounts::REQUEST_STATUS)
            ->assertJsonMissingPath('token');

        $account = CustomerAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('email', strtolower($email))
            ->firstOrFail();
        $url = '';
        Notification::assertSentTo($account, CustomerSignInLink::class, function (CustomerSignInLink $notification) use (&$url, $origin): bool {
            $url = $notification->url;

            return str_starts_with($notification->url, $origin.'/account/sign-in/')
                && str_contains($notification->toMail($notification)->subject ?? '', 'Sign in');
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

    private function tokenFrom(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    private function booking(string $tenantId, string $name, string $email, string $startsAt, string $endsAt): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Harbor',
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak',
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

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
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
}
