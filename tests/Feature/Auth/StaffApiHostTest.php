<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\User;
use App\Services\Auth\StaffApiHost;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffApiHostTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_member_signs_in_on_that_workspace_and_sees_only_that_workspace(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        $this->password($alpha['user']);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);

        $login = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.memberships.0.tenant_id', $alpha['tenant']->id)
            ->assertJsonPath('user.memberships.0.role', 'owner')
            ->assertJsonCount(1, 'user.memberships')
            ->assertJsonMissing(['tenant_id' => $beta['tenant']->id]);

        $token = $login->json('token');
        $this->assertIsString($token);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('alpha.test', '/api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.memberships.0.tenant_id', $alpha['tenant']->id)
            ->assertJsonCount(1, 'data.memberships')
            ->assertJsonMissing(['tenant_id' => $beta['tenant']->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('beta.test', '/api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.memberships.0.tenant_id', $beta['tenant']->id)
            ->assertJsonCount(1, 'data.memberships')
            ->assertJsonMissing(['tenant_id' => $alpha['tenant']->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('beta.test', '/api/v1/tenant'))
            ->assertOk()
            ->assertJsonPath('data.id', $beta['tenant']->id);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant-Id', $alpha['tenant']->id)
            ->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
                'email' => $alpha['user']->email,
                'password' => 'correct-horse-battery',
            ])->assertOk()
            ->assertJsonPath('user.memberships.0.tenant_id', $alpha['tenant']->id);

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'auth.login')
            ->where('tenant_id', $alpha['tenant']->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('api', $audit->properties['channel'] ?? null);
        $this->assertMoneyUntouched();
    }

    public function test_another_workspaces_password_is_refused_without_a_token(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->password($beta['user']);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => $beta['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', StaffApiHost::TENANT_LOGIN_FAILED);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => $beta['user']->email,
            'password' => 'not-the-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', StaffApiHost::TENANT_LOGIN_FAILED);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', StaffApiHost::TENANT_LOGIN_FAILED);

        $this->assertSame(0, PersonalAccessToken::query()->count());

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'auth.login_failed')
            ->where('tenant_id', $alpha['tenant']->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('not-the-password', (string) json_encode($log->properties));
        $this->assertStringNotContainsString('correct-horse-battery', (string) json_encode($log->properties));
        $this->assertMoneyUntouched();
    }

    public function test_a_revoked_or_invited_member_cannot_sign_in(): void
    {
        $revoked = $this->workspace('revoked.test');
        $this->password($revoked['user']);
        $revoked['membership']->forceFill(['status' => MembershipStatus::Revoked])->save();

        $this->postJson($this->tenantUrl('revoked.test', '/api/v1/login'), [
            'email' => $revoked['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', StaffApiHost::TENANT_LOGIN_FAILED);

        $invited = $this->workspace('invited.test');
        $this->password($invited['user']);
        $invited['membership']->forceFill(['status' => MembershipStatus::Invited])->save();

        $this->postJson($this->tenantUrl('invited.test', '/api/v1/login'), [
            'email' => $invited['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', StaffApiHost::TENANT_LOGIN_FAILED);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_unverified_suspended_or_foreign_id_does_not_sign_anyone_in(): void
    {
        $pending = $this->workspace('pending.test');
        $this->password($pending['user']);
        $pending['domain']->forceFill(['verified_at' => null])->save();

        $this->postJson($this->tenantUrl('pending.test', '/api/v1/login'), [
            'email' => $pending['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertNotFound()
            ->assertJsonPath('message', 'No workspace is available at this address.');

        $suspended = $this->workspace('suspended.test', 'owner', ['status' => 'suspended']);
        $this->password($suspended['user']);

        $this->postJson($this->tenantUrl('suspended.test', '/api/v1/login'), [
            'email' => $suspended['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This workspace is unavailable.');

        $terminated = $this->workspace('terminated.test', 'owner', ['status' => 'terminated']);
        $this->password($terminated['user']);

        $this->postJson($this->tenantUrl('terminated.test', '/api/v1/login'), [
            'email' => $terminated['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This workspace is unavailable.');

        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->password($alpha['user']);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
            'tenant_id' => $beta['tenant']->id,
        ])->assertForbidden()
            ->assertJsonPath('message', 'The workspace id does not match this address.');

        $this->withHeader('X-Tenant-Id', $beta['tenant']->id)
            ->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
                'email' => $alpha['user']->email,
                'password' => 'correct-horse-battery',
            ])->assertForbidden();

        $this->postJson('http://localhost/api/v1/login', [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
            'tenant_id' => $alpha['tenant']->id,
        ])->assertForbidden()
            ->assertJsonPath('message', 'The workspace id does not match this address.');

        $this->postJson('http://unknown.test/api/v1/login', [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertNotFound();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertMoneyUntouched();
    }

    public function test_an_onboarding_workspace_can_sign_in_and_the_platform_host_still_can(): void
    {
        $starting = $this->workspace('starting.test', 'owner', ['status' => 'onboarding']);
        $this->password($starting['user']);

        $this->postJson($this->tenantUrl('starting.test', '/api/v1/login'), [
            'email' => $starting['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()
            ->assertJsonPath('user.memberships.0.tenant_id', $starting['tenant']->id);

        $platform = $this->postJson('http://localhost/api/v1/login', [
            'email' => $starting['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $token = $platform->json('token');
        $this->assertIsString($token);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('http://localhost/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $starting['user']->email)
            ->assertJsonPath('data.memberships.0.tenant_id', $starting['tenant']->id);

        $gamma = $this->workspace('gamma.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('gamma.test', '/api/v1/me'))
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this workspace.')
            ->assertJsonMissing(['tenant_id' => $starting['tenant']->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->postJson($this->tenantUrl('gamma.test', '/api/v1/logout'))
            ->assertForbidden();

        $this->assertSame(2, PersonalAccessToken::query()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->postJson($this->tenantUrl('starting.test', '/api/v1/logout'))
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson($this->tenantUrl('starting.test', '/api/v1/me'))
            ->assertUnauthorized();

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertMoneyUntouched();
    }

    public function test_workspace_sign_in_is_rate_limited(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
                'email' => $alpha['user']->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/login'), [
            'email' => $alpha['user']->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    private function password(User $user): void
    {
        $user->forceFill(['password' => 'correct-horse-battery'])->save();
    }

    private function assertMoneyUntouched(): void
    {
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
    }
}
