<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Administrators;
use App\Models\AuditLog;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class RevokePlatformAdminTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_administrator_can_remove_someone_elses_access(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-staple-99',
        ]);
        $workspace = $this->workspace('alpha.test');
        $other = $workspace['user'];
        $other->forceFill([
            'name' => 'Bea Operator',
            'email' => 'bea@example.com',
            'password' => 'another-horse-staple-99',
        ])->save();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        PlatformAdministrator::factory()->create(['user_id' => $other->id]);
        $password = $other->password;
        $before = $this->counts();
        Sanctum::actingAs($admin);

        $this->getJson('http://localhost/api/v1/platform/administrators')
            ->assertOk()
            ->assertJsonFragment(['email' => 'ada@example.com', 'name' => 'Ada Admin', 'self' => true])
            ->assertJsonFragment(['email' => 'bea@example.com', 'name' => 'Bea Operator', 'self' => false])
            ->assertJsonMissingPath('data.0.password');

        $this->assertSame($before, $this->counts());

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'Bea@Example.com',
        ])->assertOk()
            ->assertJsonPath('data.email', 'bea@example.com')
            ->assertJsonPath('data.removed', true)
            ->assertDontSee('another-horse-staple-99', false)
            ->assertDontSee('correct-horse-staple-99', false);

        $fresh = $other->fresh();
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertFalse($fresh->isPlatformAdmin());
        $this->assertSame($password, $fresh->password);
        $this->assertSame('bea@example.com', $fresh->email);
        $this->assertDatabaseHas('memberships', ['id' => $workspace['membership']->id, 'user_id' => $other->id]);
        $this->assertTrue($admin->fresh()->isPlatformAdmin());

        $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->first();
        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertSame($other->id, $log->subject_id);
        $this->assertNull($log->tenant_id);
        $this->assertSame(['email' => 'bea@example.com'], $log->properties);
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->count());

        $after = $this->counts();
        $this->assertSame($before['audits'] + 1, $after['audits']);
        unset($before['audits'], $after['audits']);
        $this->assertSame($before, $after);

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'bea@example.com',
        ])->assertNotFound()
            ->assertJsonPath('message', 'That person is not a platform administrator.');

        $this->assertSame($after['payments'] ?? $this->counts()['payments'], Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->count());
    }

    public function test_revocation_is_refused_for_the_last_administrator_for_yourself_and_for_anyone_else(): void
    {
        $admin = User::factory()->create(['email' => 'ada@example.com']);
        $other = User::factory()->create(['email' => 'bea@example.com']);
        $stranger = User::factory()->create(['email' => 'cam@example.com']);
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        PlatformAdministrator::factory()->create(['user_id' => $other->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'ada@example.com',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Another platform administrator has to remove your access.');

        $this->assertTrue($admin->fresh()->isPlatformAdmin());
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->count());

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'cam@example.com',
        ])->assertNotFound()
            ->assertJsonPath('message', 'That person is not a platform administrator.');

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'missing@example.com',
        ])->assertNotFound();

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Enter a valid email.');

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => str_repeat('a', 250).'@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue($other->fresh()->isPlatformAdmin());

        PlatformAdministrator::query()->where('user_id', $other->id)->delete();

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'ada@example.com',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'The last platform administrator stays.');

        $this->assertTrue($admin->fresh()->isPlatformAdmin());
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->count());
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_workspace_member_and_a_guest_cannot_remove_platform_access(): void
    {
        $admin = User::factory()->create(['email' => 'ada@example.com']);
        $other = User::factory()->create(['email' => 'bea@example.com']);
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        PlatformAdministrator::factory()->create(['user_id' => $other->id]);
        $workspace = $this->workspace('alpha.test');

        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'bea@example.com',
        ])->assertUnauthorized();

        Sanctum::actingAs($workspace['user']);

        $this->getJson('http://localhost/api/v1/platform/administrators')->assertForbidden();
        $this->deleteJson('http://localhost/api/v1/platform/administrators', [
            'email' => 'bea@example.com',
        ])->assertForbidden();

        $this->assertTrue($other->fresh()->isPlatformAdmin());
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_the_administrators_page_removes_someone_else_and_stays_on_the_platform_host(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ada <script>alert(1)</script>',
            'email' => 'ada@example.com',
        ]);
        $other = User::factory()->create([
            'name' => 'Bea Operator',
            'email' => 'bea@example.com',
        ]);
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        PlatformAdministrator::factory()->create(['user_id' => $other->id]);
        $before = $this->counts();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/administrators')
            ->assertOk()
            ->assertSee('Administrators')
            ->assertSee('bea@example.com')
            ->assertSee('Ada &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('href="/platform/administrators"', false)
            ->assertSee('data-remove="bea@example.com"', false)
            ->assertSee('data-self="yes"', false)
            ->assertDontSee('data-remove="ada@example.com"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('<script>', false);

        $this->assertSame($before, $this->counts());

        Livewire::actingAs($admin)
            ->test(Administrators::class)
            ->assertSee('bea@example.com')
            ->call('remove', 'bea@example.com')
            ->assertSee('Platform administrator access removed.')
            ->assertSee('The last platform administrator stays.')
            ->assertDontSee('data-remove=', false);

        $this->assertFalse($other->fresh()->isPlatformAdmin());
        $this->assertTrue($admin->fresh()->isPlatformAdmin());
        $this->assertSame($before['audits'] + 1, $this->counts()['audits']);

        $alone = User::factory()->create(['email' => 'only@example.com']);
        PlatformAdministrator::query()->delete();
        PlatformAdministrator::factory()->create(['user_id' => $alone->id]);

        Livewire::actingAs($alone)
            ->test(Administrators::class)
            ->assertSee('The last platform administrator stays.')
            ->assertDontSee('data-remove=', false)
            ->call('remove', 'only@example.com')
            ->assertSee('The last platform administrator stays.');

        $this->assertTrue($alone->fresh()->isPlatformAdmin());
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_page(): void
    {
        $this->get('http://app.reserveworx.com/platform/administrators')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/administrators')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Administrators::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/administrators'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/administrators'))
            ->assertNotFound();
    }

    public function test_the_revoke_command_removes_another_administrator_and_refuses_the_last_one(): void
    {
        $admin = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-staple-99',
        ]);
        $other = User::factory()->create([
            'email' => 'bea@example.com',
            'password' => 'another-horse-staple-99',
        ]);
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        PlatformAdministrator::factory()->create(['user_id' => $other->id]);

        $this->artisan('reserveworx:revoke-platform-admin', ['email' => 'not-an-email'])
            ->assertFailed()
            ->expectsOutputToContain('Enter a valid email.');

        $this->artisan('reserveworx:revoke-platform-admin', ['email' => 'bea@example.com'])
            ->assertSuccessful()
            ->expectsOutputToContain('platform_admin=revoked email=bea@example.com');

        $this->assertStringNotContainsString('another-horse-staple-99', Artisan::output());
        $this->assertStringNotContainsString('correct-horse-staple-99', Artisan::output());
        $this->assertFalse($other->fresh()->isPlatformAdmin());
        $this->assertNotNull($other->fresh());

        $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.admin_revoked')->first();
        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertNull($log->actor_user_id);

        $this->artisan('reserveworx:revoke-platform-admin', ['email' => 'ada@example.com'])
            ->assertFailed()
            ->expectsOutputToContain('The last platform administrator stays.');

        $this->assertTrue($admin->fresh()->isPlatformAdmin());
        $this->assertSame(1, User::query()->where('email', 'bea@example.com')->count());
    }

    /**
     * @return array{audits: int, payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int, prices: int, taxes: int}
     */
    private function counts(): array
    {
        return [
            'audits' => AuditLog::withoutGlobalScope(TenantScope::class)->count(),
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
