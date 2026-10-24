<?php

namespace Tests\Feature\Web;

use App\Enums\MembershipStatus;
use App\Livewire\Staff\Team;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvite;
use App\Services\Team\RoleGuard;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_invites_a_new_person_and_they_set_a_password(): void
    {
        $alpha = $this->signedIn('alpha.test', 'owner', 'Alpha Kayaks');
        Notification::fake();

        Livewire::test(Team::class)
            ->set('email', 'New.Person@example.com')
            ->set('role', 'manager')
            ->call('invite')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertNull($user->password_set_at);
        $membership = $this->membership($alpha['tenant']->id, $user->id);
        $this->assertSame(MembershipStatus::Invited, $membership->status);
        $this->assertSame('manager', $membership->role?->slug);
        $this->assertNotNull($membership->invite_token_hash);

        $url = $this->sentUrl($user);
        $this->assertStringStartsWith('http://alpha.test/staff/invites/'.$membership->id, $url);
        $token = $this->tokenFrom($url);
        $this->assertSame(hash('sha256', $token), $membership->invite_token_hash);

        $log = $this->audit('membership.invited', $alpha['tenant']->id);
        $encoded = (string) json_encode($log->properties);
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString((string) $membership->invite_token_hash, $encoded);
        $this->assertSame('new.person@example.com', $log->properties['email']);
        $this->assertFalse($log->properties['existing_user']);

        $mail = (new TeamInvite($url, 'Alpha Kayaks', 'Manager', 7))->toMail($user);
        $this->assertSame('Join Alpha Kayaks on ReserveWorx', $mail->subject);

        Auth::logout();
        $this->get($url)->assertOk()->assertSee('Create account and join')->assertDontSee('<table', false);

        $this->from($url)->post($url, [
            'name' => 'New Person',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()?->password_set_at);

        $this->post($url, [
            'name' => 'New Person',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect('/staff');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertNotNull($user->password_set_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('New Person', $user->name);
        $this->assertSame(MembershipStatus::Active, $membership->fresh()?->status);
        $this->assertNull($membership->fresh()?->invite_token_hash);
        $this->assertSame('membership.accepted', $this->audit('membership.accepted', $alpha['tenant']->id)->action);

        Auth::logout();
        $this->get($url)->assertForbidden()->assertSee('no longer valid');
        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => 'new.person@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/staff');
    }

    public function test_an_existing_user_joins_this_workspace_and_keeps_the_password(): void
    {
        $alpha = $this->signedIn('alpha.test', 'owner', 'Alpha Kayaks');
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        $beta['user']->forceFill(['password' => bcrypt('correct-horse-battery')])->save();
        $hash = $beta['user']->fresh()->password;
        Notification::fake();

        Livewire::test(Team::class)
            ->set('email', strtoupper($beta['user']->email))
            ->set('role', 'staff')
            ->call('invite')
            ->assertHasNoErrors();

        $url = $this->sentUrl($beta['user']);
        Auth::logout();
        $this->get($url)
            ->assertOk()
            ->assertSee('Join Alpha Kayaks')
            ->assertDontSee('Create account and join');

        $this->post($url, [])->assertRedirect('/staff/login');
        $this->assertGuest();
        $membership = $this->membership($alpha['tenant']->id, $beta['user']->id);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame('staff', $membership->role?->slug);
        $this->assertSame($hash, $beta['user']->fresh()->password);
        $this->assertSame(1, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('user_id', $beta['user']->id)
            ->where('status', MembershipStatus::Active->value)
            ->count());

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $beta['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/staff');
        $this->get($this->tenantUrl('alpha.test', '/staff'))->assertOk();
        $this->get($this->tenantUrl('alpha.test', '/staff/team'))->assertForbidden();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/team'))
            ->assertOk()
            ->assertSee($beta['user']->email);
    }

    public function test_resend_replaces_the_link_and_revoke_closes_it(): void
    {
        $alpha = $this->signedIn();
        Notification::fake();
        Livewire::test(Team::class)->set('email', 'pending@example.com')->set('role', 'staff')->call('invite');
        $user = User::query()->where('email', 'pending@example.com')->firstOrFail();
        $membership = $this->membership($alpha['tenant']->id, $user->id);
        $first = $this->sentUrl($user);

        Livewire::test(Team::class)->call('resend', $membership->id)->assertHasNoErrors();
        Notification::assertSentToTimes($user, TeamInvite::class, 2);
        $urls = [];
        Notification::assertSentTo($user, TeamInvite::class, function (TeamInvite $notification) use (&$urls): bool {
            $urls[] = $notification->url;

            return str_starts_with($notification->url, 'http://alpha.test/staff/invites/');
        });
        $this->assertCount(2, $urls);
        Auth::logout();
        $this->get($urls[0])->assertForbidden()->assertSee('no longer valid');
        $this->get($urls[1])->assertOk()->assertSee('Create account and join');
        $this->assertSame('membership.invite_resent', $this->audit('membership.invite_resent', $alpha['tenant']->id)->action);

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Team::class)->call('revokeInvite', $membership->id)->assertHasNoErrors();
        $this->assertSame(MembershipStatus::Revoked, $membership->fresh()?->status);
        $this->get($urls[1])->assertForbidden()->assertSee('no longer valid');
        $this->assertSame('membership.invite_revoked', $this->audit('membership.invite_revoked', $alpha['tenant']->id)->action);
    }

    public function test_an_expired_or_foreign_link_does_not_add_a_member(): void
    {
        $alpha = $this->signedIn('alpha.test', 'owner', 'Alpha Kayaks');
        $beta = $this->workspace('beta.test');
        Notification::fake();
        Livewire::test(Team::class)->set('email', 'later@example.com')->set('role', 'staff')->call('invite');
        $user = User::query()->where('email', 'later@example.com')->firstOrFail();
        $url = $this->sentUrl($user);
        $membership = $this->membership($alpha['tenant']->id, $user->id);

        Auth::logout();
        $foreign = str_replace('http://alpha.test', 'http://beta.test', $url);
        $this->assertContains($this->get($foreign)->status(), [403, 404]);
        $this->assertSame(MembershipStatus::Invited, $membership->fresh()?->status);

        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $this->assertSame(MembershipStatus::Invited, $membership->fresh()?->status);
        $this->assertSame(0, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_the_last_owner_cannot_be_removed_or_demoted(): void
    {
        $alpha = $this->signedIn();

        Livewire::test(Team::class)
            ->call('removeMember', $alpha['membership']->id)
            ->assertHasErrors('team')
            ->assertSee(RoleGuard::LAST_OWNER);
        Livewire::test(Team::class)
            ->set('drafts.'.$alpha['membership']->id, 'manager')
            ->call('saveRole', $alpha['membership']->id)
            ->assertHasErrors('team');
        $this->assertSame('owner', $alpha['membership']->fresh()?->role?->slug);
        $this->assertSame(MembershipStatus::Active, $alpha['membership']->fresh()?->status);

        $second = User::factory()->create();
        $other = Membership::factory()->role('owner')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $second->id,
        ]);

        Livewire::test(Team::class)
            ->set('drafts.'.$alpha['membership']->id, 'manager')
            ->call('saveRole', $alpha['membership']->id)
            ->assertHasNoErrors();
        $this->assertSame('manager', $alpha['membership']->fresh()?->role?->slug);
        $this->assertSame('membership.updated', $this->audit('membership.updated', $alpha['tenant']->id)->action);

        $this->actingAs($second);
        Livewire::test(Team::class)
            ->call('removeMember', $other->id)
            ->assertHasErrors('team');
        $this->assertSame(MembershipStatus::Active, $other->fresh()?->status);
    }

    public function test_a_lower_role_cannot_escalate_even_with_the_permission(): void
    {
        $alpha = $this->signedIn('alpha.test', 'manager');
        $this->grantTeamManagement();
        $owner = User::factory()->create();
        $ownerMembership = Membership::factory()->role('owner')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $owner->id,
        ]);

        Livewire::test(Team::class)
            ->set('email', 'boss@example.com')
            ->set('role', 'owner')
            ->call('invite')
            ->assertHasErrors('role');
        $this->assertNull(User::query()->where('email', 'boss@example.com')->first());

        Livewire::test(Team::class)
            ->set('email', 'crew@example.com')
            ->set('role', 'staff')
            ->call('invite')
            ->assertHasNoErrors();

        Livewire::test(Team::class)
            ->set('drafts.'.$ownerMembership->id, 'staff')
            ->call('saveRole', $ownerMembership->id)
            ->assertHasErrors('team')
            ->assertSee(RoleGuard::ABOVE_MEMBER);
        $this->assertSame('owner', $ownerMembership->fresh()?->role?->slug);

        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/members'), [
            'name' => 'Pat Owner',
            'email' => 'pat-owner@example.com',
            'password' => 'correct-horse-battery',
            'role' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/members'), [
            'name' => 'Pat Staff',
            'email' => 'pat-staff@example.com',
            'password' => 'correct-horse-battery',
            'role' => 'staff',
        ])->assertCreated();
    }

    public function test_staff_and_managers_cannot_open_the_team_page(): void
    {
        $staff = $this->workspace('alpha.test', 'staff');
        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/team'))
            ->assertForbidden();
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('Invite people and set roles');

        $manager = $this->workspace('beta.test', 'manager');
        $this->actingAs($manager['user'])
            ->get($this->tenantUrl('beta.test', '/staff/team'))
            ->assertForbidden();
    }

    public function test_the_owner_menu_links_to_the_team_and_another_workspace_is_hidden(): void
    {
        $alpha = $this->signedIn('alpha.test', 'owner', 'Alpha Kayaks');
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        Notification::fake();
        Livewire::test(Team::class)->set('email', 'hidden@example.com')->set('role', 'staff')->call('invite');

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Invite people and set roles');
        $this->get($this->tenantUrl('alpha.test', '/staff/team'))
            ->assertOk()
            ->assertSee('hidden@example.com')
            ->assertDontSee('<table', false);

        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('beta.test', '/staff/team'))
            ->assertOk()
            ->assertDontSee('hidden@example.com');
    }

    public function test_a_team_action_from_another_workspace_domain_is_refused(): void
    {
        $alpha = $this->signedIn();
        $beta = $this->workspace('beta.test');
        $staff = User::factory()->create(['email' => 'sam@example.com']);
        $membership = Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $staff->id,
        ]);

        $html = $this->get($this->tenantUrl('alpha.test', '/staff/team'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
        $this->assertNotEmpty($match);
        $snapshot = html_entity_decode($match[1]);
        $uri = app('livewire')->getUpdateUri();
        $payload = ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'removeMember', 'params' => [$membership->id]]],
        ]]];
        $headers = ['X-Livewire' => '1', 'X-CSRF-TOKEN' => csrf_token()];

        $this->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertForbidden();
        $this->assertSame(MembershipStatus::Active, $membership->fresh()?->status);

        $this->actingAs($beta['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertNotFound();
        $this->assertSame(MembershipStatus::Active, $membership->fresh()?->status);

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Team::class)->call('removeMember', $membership->id)->assertHasNoErrors();
        $this->assertSame(MembershipStatus::Revoked, $membership->fresh()?->status);
        $this->assertSame('sam@example.com', $this->audit('membership.revoked', $alpha['tenant']->id)->properties['email']);
    }

    public function test_invitation_sends_stop_after_ten_in_a_minute(): void
    {
        $this->signedIn();
        Notification::fake();
        $component = Livewire::test(Team::class);

        for ($i = 1; $i <= 10; $i++) {
            $component->set('email', 'person'.$i.'@example.com')->set('role', 'staff')->call('invite')->assertHasNoErrors();
        }

        $component->set('email', 'overflow@example.com')->set('role', 'staff')->call('invite');
        $this->assertNull(User::query()->where('email', 'overflow@example.com')->first());
        $component->assertSee('Wait a minute');
    }

    public function test_a_signed_in_stranger_cannot_accept_someone_elses_invitation(): void
    {
        $alpha = $this->signedIn();
        Notification::fake();
        Livewire::test(Team::class)->set('email', 'guest@example.com')->set('role', 'staff')->call('invite');
        $url = $this->sentUrl(User::query()->where('email', 'guest@example.com')->firstOrFail());

        $this->get($url)->assertOk()->assertSee('Sign out of the other account');
        $this->post($url, [
            'name' => 'Guest',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasErrors('email');
        $this->assertSame(MembershipStatus::Invited, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->where('user_id', User::query()->where('email', 'guest@example.com')->value('id'))
            ->first()?->status);
    }

    /**
     * @param  array<string, mixed>  $tenantAttributes
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $host = 'alpha.test', string $role = 'owner', string|array $tenantAttributes = []): array
    {
        if (is_string($tenantAttributes)) {
            $tenantAttributes = ['name' => $tenantAttributes];
        }

        $workspace = $this->workspace($host, $role, $tenantAttributes);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function grantTeamManagement(): void
    {
        $role = Role::query()->where('slug', 'manager')->firstOrFail();
        $permission = Permission::query()->where('slug', 'members.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    private function membership(string $tenantId, string $userId): Membership
    {
        return Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    private function sentUrl(User $user): string
    {
        $url = '';
        Notification::assertSentTo($user, TeamInvite::class, function (TeamInvite $notification) use (&$url): bool {
            $url = $notification->url;

            return $url !== '';
        });
        $this->assertNotSame('', $url);

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function audit(string $action, string $tenantId): AuditLog
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('action', $action)
            ->latest('created_at')
            ->firstOrFail();
    }

    private function tokenFrom(string $url): string
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? '';
        $this->assertIsString($token);

        return $token;
    }
}
