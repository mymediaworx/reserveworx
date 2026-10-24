<?php

namespace Tests\Feature\Web;

use App\Enums\FeePayer;
use App\Enums\ScheduleOwnerType;
use App\Enums\TenantStatus;
use App\Livewire\Staff\Dashboard;
use App\Models\AuditLog;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PlatformAdministrator;
use App\Models\Service;
use App\Models\SignupAcceptance;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantSetting;
use App\Models\User;
use App\Notifications\VerifySignupEmail;
use App\Services\Onboarding\OnboardingChecklist;
use App\Services\TenantLifecycle;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicSignupTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['reserveworx.signup_rate_per_minute' => 100]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_public_signup_creates_an_onboarding_workspace_and_lands_the_owner_after_verification(): void
    {
        $this->signup([
            'business_name' => 'Lakeside Paddle',
            'slug' => 'My-Shop',
            'owner_name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'direct_fee_bps' => '1',
            'third_party_fee_bps' => '1',
            'fee_payer' => 'operator_absorbed',
        ])->assertRedirect('/signup/check-email');

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'my-shop')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame(TenantStatus::Onboarding, $tenant->status);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));

        $setting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(300, $setting->direct_fee_bps);
        $this->assertSame(100, $setting->third_party_fee_bps);
        $this->assertSame(0, $setting->direct_fee_fixed_cents);
        $this->assertSame(0, $setting->third_party_fee_fixed_cents);
        $this->assertNull($setting->direct_fee_minimum_cents);
        $this->assertNull($setting->third_party_fee_minimum_cents);
        $this->assertSame(FeePayer::CustomerPaid, $setting->fee_payer);

        $membership = Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->firstOrFail();
        $this->assertSame('owner', $membership->role?->slug);
        $this->assertSame('active', $membership->status->value);

        $domain = $tenant->domains()->firstOrFail();
        $this->assertSame('my-shop.reserveworx.test', $domain->hostname);
        $this->assertNotNull($domain->verified_at);
        $this->assertTrue($domain->is_primary);

        $acceptance = SignupAcceptance::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('2026-10-24', $acceptance->terms_version);
        $this->assertNotNull($acceptance->accepted_at);
        $audit = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'signup.terms_accepted')->firstOrFail();
        $this->assertSame('2026-10-24', $audit->properties['terms_version'] ?? null);
        $this->assertNotEmpty($audit->properties['accepted_at'] ?? null);

        $this->assertSame('Lakeside Paddle', TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->value('display_name'));

        $url = $this->verificationUrl($user);
        $this->assertStringNotContainsString('correct-horse-battery', $url);
        $landed = $this->followVerification($url);
        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertStringStartsWith('http://my-shop.reserveworx.test/staff/enter/', $landed);

        $this->get('http://my-shop.reserveworx.test/staff')
            ->assertOk()
            ->assertSee('Finish setting up')
            ->assertSee('Connect Stripe')
            ->assertSee('Go live');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform')
            ->assertOk()
            ->assertSee('Lakeside Paddle')
            ->assertSee('my-shop.reserveworx.test')
            ->assertSee('Onboarding');
    }

    public function test_a_duplicate_or_reserved_subdomain_is_refused(): void
    {
        $this->signup()->assertRedirect('/signup/check-email');
        $this->signup(['email' => 'second@example.com'])->assertSessionHasErrors('slug');
        $this->assertSame(1, Tenant::query()->where('slug', 'lakeside')->count());

        $this->workspace('pier.reserveworx.test', tenantAttributes: ['slug' => 'not-pier', 'name' => 'Pier Boats']);
        $this->from('http://app.reserveworx.com/signup')
            ->signup(['slug' => 'pier', 'email' => 'pier@example.com'])
            ->assertSessionHasErrors('slug');
        $this->assertNull(Tenant::query()->where('slug', 'pier')->first());

        User::factory()->create(['email' => 'taken@example.com']);
        $this->from('http://app.reserveworx.com/signup')
            ->signup(['slug' => 'fresh-dock', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
        $this->assertNull(Tenant::query()->where('slug', 'fresh-dock')->first());

        foreach (['app', 'www', 'admin', 'api', 'mail', 'staff', 'platform'] as $reserved) {
            $this->from('http://app.reserveworx.com/signup')
                ->signup(['slug' => $reserved, 'email' => $reserved.'@example.com'])
                ->assertSessionHasErrors('slug');
            $this->assertNull(Tenant::query()->where('slug', $reserved)->first());
        }

        foreach (['ab', 'a b', '-bad', 'bad-'] as $invalid) {
            $this->from('http://app.reserveworx.com/signup')
                ->signup(['slug' => $invalid, 'email' => 'invalid-'.md5($invalid).'@example.com'])
                ->assertSessionHasErrors('slug');
        }

        $this->getJson('http://app.reserveworx.com/signup/availability?slug=lakeside')
            ->assertOk()
            ->assertExactJson([
                'slug' => 'lakeside',
                'hostname' => 'lakeside.reserveworx.test',
                'status' => 'taken',
            ]);
        $this->getJson('http://app.reserveworx.com/signup/availability?slug=APP')
            ->assertOk()
            ->assertJson(['status' => 'reserved', 'hostname' => 'app.reserveworx.test']);
        $this->getJson('http://app.reserveworx.com/signup/availability?slug=cedar-lake')
            ->assertOk()
            ->assertJson(['status' => 'available', 'hostname' => 'cedar-lake.reserveworx.test']);
        $available = $this->getJson('http://app.reserveworx.com/signup/availability?slug=pier');
        $available->assertOk()->assertJson(['status' => 'taken']);
        $available->assertDontSee('Pier Boats');
        $this->getJson('http://app.reserveworx.com/signup/availability?slug=no')
            ->assertOk()
            ->assertExactJson(['slug' => 'no', 'hostname' => null, 'status' => 'invalid']);
    }

    public function test_email_verification_is_required_before_going_live(): void
    {
        $this->signup();
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'lakeside')->firstOrFail();
        $this->finishSetup($tenant);
        $other = User::factory()->create(['name' => 'Other Owner']);
        Membership::factory()->role('owner')->create([
            'tenant_id' => $tenant->id,
            'user_id' => $other->id,
        ]);

        app(TenantManager::class)->set($tenant);
        $this->actingAs($other);
        Livewire::test(Dashboard::class)
            ->call('goLive')
            ->assertSet('problem', 'Finish the required steps before going live.');
        $this->assertSame(TenantStatus::Onboarding, $tenant->fresh()?->status);
        $this->assertNull($user->fresh()?->email_verified_at);

        $url = $this->verificationUrl($user);
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['signature'] = 'deadbeef';
        $tampered = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'app.reserveworx.com').($parts['path'] ?? '').'?'.http_build_query($query);
        $this->get($tampered)->assertForbidden();
        $this->assertNull($user->fresh()?->email_verified_at);

        $otherTenant = $this->workspace('other.test')['tenant'];
        $swapped = str_replace('/'.$tenant->id.'/', '/'.$otherTenant->id.'/', $url);
        $this->get($swapped)->assertForbidden();

        $this->get($url)->assertRedirect();
        $this->assertNotNull($user->fresh()?->email_verified_at);

        app(TenantManager::class)->set($tenant->fresh() ?? $tenant);
        $this->actingAs($other);
        Livewire::test(Dashboard::class)->call('goLive')->assertRedirect('/staff');
        $this->assertSame(TenantStatus::Active, $tenant->fresh()?->status);

        Notification::assertSentToTimes($user, VerifySignupEmail::class, 1);
        $this->from('http://app.reserveworx.com/signup/check-email')
            ->post('http://app.reserveworx.com/signup/resend', ['email' => 'missing@example.com'])
            ->assertRedirect('/signup/check-email')
            ->assertSessionHas('status');
        Notification::assertSentToTimes($user, VerifySignupEmail::class, 1);
    }

    public function test_signup_is_rate_limited(): void
    {
        config(['reserveworx.signup_rate_per_minute' => 2]);

        $this->post('http://app.reserveworx.com/signup', [])->assertStatus(302);
        $this->post('http://app.reserveworx.com/signup', [])->assertStatus(302);
        $this->post('http://app.reserveworx.com/signup', [])->assertStatus(429);
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_a_signup_cannot_see_or_activate_another_workspace(): void
    {
        $this->signup([
            'business_name' => 'Alpha Dock',
            'slug' => 'alpha-dock',
            'email' => 'alpha@example.com',
        ]);
        $this->signup([
            'business_name' => 'Beta Dock',
            'slug' => 'beta-dock',
            'email' => 'beta@example.com',
        ]);
        $alphaUser = User::query()->where('email', 'alpha@example.com')->firstOrFail();
        $betaUser = User::query()->where('email', 'beta@example.com')->firstOrFail();
        $alpha = Tenant::query()->where('slug', 'alpha-dock')->firstOrFail();
        $beta = Tenant::query()->where('slug', 'beta-dock')->firstOrFail();
        $this->finishSetup($beta);

        $this->assertSame(1, Membership::withoutGlobalScope(TenantScope::class)->where('user_id', $alphaUser->id)->count());
        $this->assertSame($alpha->id, Membership::withoutGlobalScope(TenantScope::class)->where('user_id', $alphaUser->id)->value('tenant_id'));

        $alphaProgress = app(OnboardingChecklist::class)->assess($alpha);
        $this->assertFalse($alphaProgress->stripeConnected);
        $this->assertFalse($alphaProgress->serviceReady);
        $this->assertFalse($alphaProgress->hoursReady);
        $this->assertFalse($alphaProgress->brandingReady);
        $this->assertFalse($alphaProgress->testBookingPlaced);
        $this->assertFalse($alphaProgress->canGoLive);

        $betaProgress = app(OnboardingChecklist::class)->assess($beta);
        $this->assertTrue($betaProgress->stripeConnected);
        $this->assertTrue($betaProgress->serviceAndHoursReady());
        $this->assertTrue($betaProgress->brandingReady);
        $this->assertTrue($betaProgress->testBookingPlaced);
        $this->assertFalse($betaProgress->canGoLive);

        $this->actingAs($alphaUser)
            ->get('http://beta-dock.reserveworx.test/staff')
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->get('http://alpha-dock.reserveworx.test/staff')
            ->assertOk()
            ->assertSee('Add a service and opening hours.')
            ->assertDontSee('Beta Dock')
            ->assertDontSee('beta@example.com');

        $this->actingAs($betaUser)
            ->get('http://beta-dock.reserveworx.test/staff')
            ->assertOk()
            ->assertSee('A service and opening hours are set.')
            ->assertDontSee('Alpha Dock');

        app(TenantManager::class)->set($alpha);
        $this->actingAs($alphaUser);
        Livewire::test(Dashboard::class)->call('goLive');
        $this->assertSame(TenantStatus::Onboarding, $alpha->fresh()?->status);
        $this->assertSame(TenantStatus::Onboarding, $beta->fresh()?->status);
    }

    public function test_go_live_requires_each_checklist_step_and_obeys_the_lifecycle(): void
    {
        [$user, $tenant] = $this->verifiedSignup();
        app(TenantManager::class)->set($tenant);
        $this->actingAs($user);
        $checklist = app(OnboardingChecklist::class);

        $this->assertFalse($checklist->assess($tenant)->canGoLive);
        Livewire::test(Dashboard::class)
            ->call('goLive')
            ->assertSet('problem', 'Finish the required steps before going live.');
        $this->assertSame(TenantStatus::Onboarding, $tenant->fresh()?->status);

        $account = StripeAccount::query()->create([
            'tenant_id' => $tenant->id,
            'stripe_account_id' => 'acct_'.$tenant->id,
            'card_payments_status' => 'pending',
            'livemode' => false,
        ]);
        $this->assertFalse($checklist->assess($tenant)->stripeConnected);
        $account->forceFill(['card_payments_status' => 'active'])->save();
        $this->assertTrue($checklist->assess($tenant)->stripeConnected);
        $this->assertFalse($checklist->assess($tenant)->canGoLive);

        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'UTC']);
        Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);
        $this->assertTrue($checklist->assess($tenant)->serviceReady);
        $this->assertFalse($checklist->assess($tenant)->hoursReady);
        $this->assertFalse($checklist->assess($tenant)->canGoLive);

        AvailabilitySchedule::query()->create([
            'tenant_id' => $tenant->id,
            'owner_type' => ScheduleOwnerType::Location,
            'owner_id' => $location->id,
            'weekday' => 1,
            'opens_local' => '09:00:00',
            'closes_local' => '17:00:00',
        ]);
        $this->assertTrue($checklist->assess($tenant)->serviceAndHoursReady());
        $this->assertFalse($checklist->assess($tenant)->brandingReady);

        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->update(['support_email' => 'help@example.com']);
        $this->assertTrue($checklist->assess($tenant)->brandingReady);
        $this->assertFalse($checklist->assess($tenant)->testBookingPlaced);

        $service = Service::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail();
        Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => '2026-12-01 15:00:00',
            'ends_at' => '2026-12-01 16:00:00',
            'participants' => 1,
            'payload_hash' => str_repeat('b', 64),
        ]);
        $this->assertTrue($checklist->assess($tenant)->canGoLive);

        Livewire::test(Dashboard::class)->call('goLive')->assertRedirect('/staff');
        $this->assertSame(TenantStatus::Active, $tenant->fresh()?->status);
        $changed = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.status_changed')->firstOrFail();
        $this->assertSame('onboarding', $changed->properties['before'] ?? null);
        $this->assertSame('active', $changed->properties['after'] ?? null);
        $this->assertSame('go_live', $changed->properties['via'] ?? null);

        $this->get('http://lakeside.reserveworx.test/staff')
            ->assertOk()
            ->assertDontSee('Finish setting up');
    }

    public function test_go_live_stops_when_the_lifecycle_refuses(): void
    {
        [$user, $tenant] = $this->verifiedSignup('cedar-lake', 'cedar@example.com');
        $this->finishSetup($tenant);
        $this->mock(TenantLifecycle::class, function ($mock): void {
            $mock->shouldReceive('canTransition')
                ->once()
                ->with(TenantStatus::Onboarding, TenantStatus::Active)
                ->andReturn(false);
        });

        app(TenantManager::class)->set($tenant);
        $this->actingAs($user);
        Livewire::test(Dashboard::class)
            ->call('goLive')
            ->assertSet('problem', 'That status change is not allowed.');
        $this->assertSame(TenantStatus::Onboarding, $tenant->fresh()?->status);
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.status_changed')->count());
    }

    public function test_a_honeypot_or_missing_terms_creates_nothing(): void
    {
        $this->signup(['company_url' => 'https://spam.test'])->assertRedirect('/signup/check-email');
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
        Notification::assertNothingSent();

        $this->from('http://app.reserveworx.com/signup')
            ->signup(['accepted_terms' => '0'])
            ->assertSessionHasErrors('accepted_terms');
        $this->assertSame(0, SignupAcceptance::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_signup_is_not_served_on_a_tenant_domain(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/signup'))->assertNotFound();
        $this->post($this->tenantUrl('alpha.test', '/signup'), $this->payload())->assertNotFound();
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_a_staff_member_cannot_go_live(): void
    {
        [$owner, $tenant] = $this->verifiedSignup();
        $this->finishSetup($tenant);
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
        ]);

        app(TenantManager::class)->set($tenant);
        $this->actingAs($staff);

        Livewire::test(Dashboard::class)->call('goLive')->assertForbidden();
        $this->assertSame(TenantStatus::Onboarding, $tenant->fresh()?->status);
        $this->assertNotSame($staff->id, $owner->id);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return TestResponse
     */
    private function signup(array $overrides = [])
    {
        return $this->post('http://app.reserveworx.com/signup', $this->payload($overrides));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Lakeside Paddle',
            'slug' => 'lakeside',
            'owner_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'accepted_terms' => '1',
            'company_url' => '',
        ], $overrides);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function verifiedSignup(string $slug = 'lakeside', string $email = 'ada@example.com'): array
    {
        $this->signup([
            'slug' => $slug,
            'email' => $email,
            'business_name' => 'Lakeside Paddle',
        ]);
        $user = User::query()->where('email', $email)->firstOrFail();
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $this->get($this->verificationUrl($user))->assertRedirect();
        $this->assertNotNull($user->fresh()?->email_verified_at);

        return [$user, $tenant];
    }

    private function verificationUrl(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifySignupEmail::class, function (VerifySignupEmail $notification) use (&$url): bool {
            $url = $notification->url;

            return str_contains($notification->url, '/signup/verify/');
        });
        $this->assertIsString($url);

        return $url;
    }

    private function followVerification(string $url): string
    {
        $verified = $this->get($url);
        $verified->assertRedirect();
        $location = (string) $verified->headers->get('Location');
        $this->dropStaffSession();
        $this->get($location)->assertRedirect('/staff');

        return $location;
    }

    private function finishSetup(Tenant $tenant): void
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'UTC',
            'name' => $tenant->name.' dock',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);
        AvailabilitySchedule::query()->create([
            'tenant_id' => $tenant->id,
            'owner_type' => ScheduleOwnerType::Location,
            'owner_id' => $location->id,
            'weekday' => 1,
            'opens_local' => '09:00:00',
            'closes_local' => '17:00:00',
        ]);
        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->update(['support_email' => 'help@example.com']);
        StripeAccount::query()->create([
            'tenant_id' => $tenant->id,
            'stripe_account_id' => 'acct_'.$tenant->id,
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => 'held',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => '2026-12-01 15:00:00',
            'ends_at' => '2026-12-01 16:00:00',
            'participants' => 2,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function dropStaffSession(): void
    {
        $this->flushSession();
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
    }
}
