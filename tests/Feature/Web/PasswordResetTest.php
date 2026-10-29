<?php

namespace Tests\Feature\Web;

use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Services\Auth\PasswordResets;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const OLD_PASSWORD = 'correct-horse-battery';

    private const NEW_PASSWORD = 'fresh-harbor-paddle';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear(md5('password-reset127.0.0.1'));
    }

    public function test_reset_links_expire_after_sixty_minutes(): void
    {
        $this->assertSame(60, config('auth.passwords.users.expire'));
    }

    public function test_sign_in_pages_link_to_the_reset_request(): void
    {
        $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);

        $this->get($this->tenantUrl('alpha.test', '/staff/login'))
            ->assertOk()
            ->assertSee('Forgot password', false)
            ->assertSee('/staff/forgot-password', false);

        $this->get('http://app.reserveworx.com/platform/login')
            ->assertOk()
            ->assertSee('Forgot password', false)
            ->assertSee('/platform/forgot-password', false);
    }

    public function test_admin_login_on_a_tenant_host_leads_to_the_staff_sign_in_with_reset_link(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);

        $this->get($this->tenantUrl('alpha.test', '/admin/login'))->assertRedirect('/staff/login');
        $this->get($this->tenantUrl('alpha.test', '/staff/login'))
            ->assertOk()
            ->assertSee('Forgot password?', false);

        $this->post($this->tenantUrl('alpha.test', '/admin/login'), [
            'email' => $alpha['user']->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/staff/login')->assertSessionHasErrors('email');
    }

    public function test_a_team_member_can_reset_a_password_from_the_emailed_link(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->password($alpha['user']);

        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->get($url)
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('Alpha Kayaks');

        $this->post($this->tenantUrl('alpha.test', '/staff/reset-password'), [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/staff/login')
            ->assertSessionHas('status', 'Your password has been reset. Sign in with the new one.');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $alpha['user']->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $alpha['user']->email]);

        $log = DB::table('audit_logs')->where('action', 'auth.password_reset')->first();
        $this->assertNotNull($log);
        $this->assertSame($alpha['tenant']->id, $log->tenant_id);
        $properties = json_decode((string) $log->properties, true);
        $this->assertSame('web', $properties['channel']);
        $this->assertSame($alpha['user']->email, $properties['email']);
        $this->assertArrayNotHasKey('token', $properties);
        $this->assertArrayNotHasKey('password', $properties);

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => self::OLD_PASSWORD,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect('/staff');
        $this->assertAuthenticatedAs($alpha['user']);
    }

    public function test_unknown_and_ineligible_emails_get_the_same_reply_and_no_link(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        Notification::fake();

        $this->from($this->tenantUrl('alpha.test', '/staff/forgot-password'))
            ->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), [
                'email' => 'nobody@example.test',
            ])->assertRedirect('/staff/forgot-password')
            ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        $this->from($this->tenantUrl('alpha.test', '/staff/forgot-password'))
            ->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), [
                'email' => $beta['user']->email,
            ])->assertRedirect('/staff/forgot-password')
            ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $beta['user']->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $alpha['user']->email]);
    }

    public function test_a_second_request_inside_a_minute_does_not_send_another_link(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);
        Notification::fake();

        $this->from($this->tenantUrl('alpha.test', '/staff/forgot-password'))
            ->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), ['email' => $alpha['user']->email])
            ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        $this->from($this->tenantUrl('alpha.test', '/staff/forgot-password'))
            ->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), ['email' => $alpha['user']->email])
            ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        Notification::assertSentToTimes($alpha['user'], ResetPasswordLink::class, 1);
    }

    public function test_the_sixth_request_from_one_address_is_throttled(): void
    {
        $this->workspace('alpha.test');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from($this->tenantUrl('alpha.test', '/staff/forgot-password'))
                ->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), [
                    'email' => 'nobody-'.$attempt.'@example.test',
                ])->assertRedirect('/staff/forgot-password')
                ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);
        }

        $this->post($this->tenantUrl('alpha.test', '/staff/forgot-password'), [
            'email' => 'nobody-6@example.test',
        ])->assertStatus(429);
    }

    public function test_an_expired_link_does_not_change_the_password(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);
        $this->signInElsewhere($alpha['user'], 'expired-reset-session');
        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->travel(61)->minutes();

        $this->post($this->tenantUrl('alpha.test', '/staff/reset-password'), [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['email' => PasswordResets::INVALID_STATUS]);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $alpha['user']->fresh()->password));
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $alpha['user']->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'expired-reset-session', 'user_id' => $alpha['user']->id]);
    }

    public function test_a_password_reset_ends_api_tokens_and_other_sessions(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $other = User::factory()->create();
        $this->password($alpha['user']);
        $token = $this->signInElsewhere($alpha['user'], 'reset-other-device');
        $otherToken = $this->signInElsewhere($other, 'someone-else-session');
        $money = $this->moneyCounts();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('alpha.test', '/api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.email', $alpha['user']->email);

        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->app['auth']->forgetGuards();
        $this->post($this->tenantUrl('alpha.test', '/staff/reset-password'), [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/staff/login')
            ->assertSessionHas('status', 'Your password has been reset. Sign in with the new one.');

        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $alpha['user']->id)->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'reset-other-device']);
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $other->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else-session', 'user_id' => $other->id]);
        $this->assertSame($money, $this->moneyCounts());
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $alpha['user']->fresh()->password));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('alpha.test', '/api/v1/me'))
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson('http://localhost/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $other->email);
    }

    public function test_a_link_from_one_workspace_does_not_reset_a_password_on_another(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->workspace('beta.test');
        $this->password($alpha['user']);
        $this->signInElsewhere($alpha['user'], 'wrong-host-session');
        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->post($this->tenantUrl('beta.test', '/staff/reset-password'), [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['email' => PasswordResets::INVALID_STATUS]);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $alpha['user']->fresh()->password));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $alpha['user']->email]);
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $alpha['user']->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'wrong-host-session', 'user_id' => $alpha['user']->id]);
    }

    public function test_a_platform_administrator_can_reset_from_the_platform_host(): void
    {
        $admin = $this->platformAdmin();
        $url = $this->captureResetUrl(
            $admin,
            'http://app.reserveworx.com/platform/forgot-password',
            'http://app.reserveworx.com/platform/reset-password/',
        );

        $this->get($url)->assertOk()->assertSee('Choose a new password')->assertSee('ReserveWorx');

        $this->post('http://app.reserveworx.com/platform/reset-password', [
            'token' => $this->tokenFrom($url),
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/platform/login');

        $log = DB::table('audit_logs')->where('action', 'auth.password_reset')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->tenant_id);
        $properties = json_decode((string) $log->properties, true);
        $this->assertSame('platform_web', $properties['channel']);

        $this->post('http://app.reserveworx.com/platform/login', [
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect('/platform');
    }

    public function test_a_workspace_member_gets_no_platform_link_and_cannot_use_a_staff_link_there(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);
        Notification::fake();

        $this->from('http://app.reserveworx.com/platform/forgot-password')
            ->post('http://app.reserveworx.com/platform/forgot-password', [
                'email' => $alpha['user']->email,
            ])->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $alpha['user']->email]);

        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->post('http://app.reserveworx.com/platform/reset-password', [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['email' => PasswordResets::INVALID_STATUS]);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $alpha['user']->fresh()->password));
    }

    public function test_reset_pages_stay_on_their_own_host(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/staff/forgot-password'))
            ->assertOk()
            ->assertSee('Email me a reset link');
        $this->get('http://app.reserveworx.com/staff/forgot-password')->assertNotFound();
        $this->get('http://app.reserveworx.com/platform/forgot-password')
            ->assertOk()
            ->assertSee('Email me a reset link');
        $this->get($this->tenantUrl('alpha.test', '/platform/forgot-password'))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/reset-password/'.str_repeat('a', 63)))->assertNotFound();
    }

    public function test_a_short_password_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);
        $url = $this->captureResetUrl(
            $alpha['user'],
            $this->tenantUrl('alpha.test', '/staff/forgot-password'),
            'http://alpha.test/staff/reset-password/',
        );

        $this->post($this->tenantUrl('alpha.test', '/staff/reset-password'), [
            'token' => $this->tokenFrom($url),
            'email' => $alpha['user']->email,
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $alpha['user']->fresh()->password));
    }

    private function password(User $user): void
    {
        $user->forceFill(['password' => self::OLD_PASSWORD])->save();
    }

    private function signInElsewhere(User $user, string $sessionId): string
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.8',
            'user_agent' => 'other-device',
            'payload' => 'other-device',
            'last_activity' => time(),
        ]);

        return $user->createToken('api')->plainTextToken;
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int}
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        PlatformAdministrator::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function captureResetUrl(User $user, string $postUrl, string $expectedPrefix): string
    {
        Notification::fake();

        $this->from($postUrl)
            ->post($postUrl, ['email' => $user->email])
            ->assertSessionHas('status', PasswordResets::REQUEST_STATUS);

        $url = '';
        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use (&$url, $expectedPrefix): bool {
            $url = $notification->url;

            return str_starts_with($notification->url, $expectedPrefix);
        });

        return $url;
    }

    private function tokenFrom(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
