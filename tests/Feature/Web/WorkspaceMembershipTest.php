<?php

namespace Tests\Feature\Web;

use App\Enums\FeePayer;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_one_login_can_hold_a_different_role_in_each_workspace(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'tenant_id'));
        $this->assertTrue(Schema::hasColumns('memberships', ['tenant_id', 'user_id', 'role_id']));

        $user = User::factory()->create();
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'staff', ['name' => 'Beta Boats']);

        Membership::factory()->role('owner')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $user->id,
        ]);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $user->id,
        ]);

        $roles = $user->memberships()->with('role')->get()->mapWithKeys(
            fn (Membership $membership): array => [$membership->tenant_id => $membership->role?->slug],
        );

        $this->assertSame('owner', $roles[$alpha['tenant']->id]);
        $this->assertSame('staff', $roles[$beta['tenant']->id]);
        $this->assertCount(2, $user->memberships()->get());

        $this->expectException(QueryException::class);
        Membership::factory()->role('manager')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_an_owner_can_add_a_business_without_changing_fee_rates(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->update(['direct_fee_bps' => 250, 'fee_payer' => FeePayer::OperatorAbsorbed->value]);

        $this->actingAs($alpha['user'])
            ->post($this->tenantUrl('alpha.test', '/staff/businesses'), [
                'name' => 'Cedar Lake',
                'slug' => 'cedar-lake',
                'hostname' => 'cedar.example.com',
                'direct_fee_bps' => 1,
                'third_party_fee_bps' => 1,
                'fee_payer' => 'operator_absorbed',
            ])
            ->assertRedirect();

        $created = Tenant::query()->where('slug', 'cedar-lake')->first();
        $this->assertInstanceOf(Tenant::class, $created);
        $this->assertSame(TenantStatus::Onboarding, $created->status);

        $setting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $created->id)->first();
        $this->assertInstanceOf(TenantSetting::class, $setting);
        $this->assertSame(300, $setting->direct_fee_bps);
        $this->assertSame(100, $setting->third_party_fee_bps);
        $this->assertSame(0, $setting->direct_fee_fixed_cents);
        $this->assertSame(0, $setting->third_party_fee_fixed_cents);
        $this->assertNull($setting->direct_fee_minimum_cents);
        $this->assertNull($setting->third_party_fee_minimum_cents);
        $this->assertSame(FeePayer::CustomerPaid, $setting->fee_payer);

        $kept = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->first();
        $this->assertInstanceOf(TenantSetting::class, $kept);
        $this->assertSame(250, $kept->direct_fee_bps);
        $this->assertSame(FeePayer::OperatorAbsorbed, $kept->fee_payer);

        $membership = Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $created->id)
            ->where('user_id', $alpha['user']->id)
            ->with('role')
            ->first();
        $this->assertSame('owner', $membership?->role?->slug);
        $this->assertSame('active', $membership?->status->value);

        $branding = TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $created->id)->first();
        $this->assertSame('Cedar Lake', $branding?->display_name);

        $domain = Domain::withoutGlobalScope(TenantScope::class)->where('tenant_id', $created->id)->first();
        $this->assertSame('cedar.example.com', $domain?->hostname);
        $this->assertNull($domain?->verified_at);

        $this->get($this->tenantUrl('alpha.test', '/staff/portfolio?business='.$created->id))
            ->assertOk()
            ->assertSee('Cedar Lake');
    }

    public function test_staff_cannot_add_a_business_and_a_taken_slug_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff', ['name' => 'Alpha Kayaks']);
        $before = Tenant::query()->count();

        $this->actingAs($alpha['user'])
            ->post($this->tenantUrl('alpha.test', '/staff/businesses'), [
                'name' => 'Cedar Lake',
                'slug' => 'cedar-lake',
            ])
            ->assertForbidden();

        $this->assertSame($before, Tenant::query()->count());

        $owner = $this->workspace('owner.test', 'owner', ['name' => 'Owner Dock']);
        $this->actingAs($owner['user'])
            ->from($this->tenantUrl('owner.test', '/staff/businesses/new'))
            ->post($this->tenantUrl('owner.test', '/staff/businesses'), [
                'name' => 'Copy',
                'slug' => $owner['tenant']->slug,
            ])
            ->assertSessionHasErrors('slug');

        $this->actingAs($owner['user'])
            ->from($this->tenantUrl('owner.test', '/staff/businesses/new'))
            ->post($this->tenantUrl('owner.test', '/staff/businesses'), [
                'name' => 'Platform',
                'slug' => 'platform-host',
                'hostname' => 'localhost',
            ])
            ->assertSessionHasErrors('hostname');

        $this->assertNull(Tenant::query()->where('slug', 'platform-host')->first());
    }

    public function test_an_owner_can_switch_to_another_business_and_the_token_is_single_use(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'staff', ['name' => 'Beta Boats']);
        Membership::factory()->role('owner')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        $alpha['user']->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/staff');

        $switched = $this->post($this->tenantUrl('alpha.test', '/staff/switch/'.$beta['tenant']->id));
        $switched->assertRedirect();
        $location = (string) $switched->headers->get('Location');
        $this->assertStringContainsString('http://beta.test/staff/enter/', $location);

        $this->dropStaffSession();
        $this->get($location)->assertRedirect('/staff');
        $this->get($this->tenantUrl('beta.test', '/staff'))
            ->assertOk()
            ->assertSee('Beta Boats');

        $this->dropStaffSession();
        $this->get($location)->assertForbidden();
        $this->get($this->tenantUrl('beta.test', '/staff'))->assertRedirect('/staff/login');
    }

    public function test_a_switch_token_for_one_business_is_refused_on_another_address(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        $gamma = $this->workspace('gamma.test', 'owner', ['name' => 'Gamma Gear']);
        Membership::factory()->role('owner')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        $alpha['user']->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ]);

        $location = (string) $this->post($this->tenantUrl('alpha.test', '/staff/switch/'.$beta['tenant']->id))
            ->headers->get('Location');
        $token = basename(parse_url($location, PHP_URL_PATH) ?: '');

        $this->dropStaffSession();
        $this->get($this->tenantUrl('gamma.test', '/staff/enter/'.$token))->assertForbidden();
        $this->get($this->tenantUrl('beta.test', '/staff/enter/'.$token))->assertRedirect('/staff');
    }

    public function test_switch_and_portfolio_stay_inside_the_current_workspace_permissions(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        $booking = $this->booking($alpha['tenant']);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Save settings');

        $this->get($this->tenantUrl('beta.test', '/staff/settings'))
            ->assertOk()
            ->assertDontSee('Save settings');

        $this->get($this->tenantUrl('beta.test', '/staff/portfolio'))->assertForbidden();
        $this->get($this->tenantUrl('beta.test', '/staff/businesses/new'))->assertForbidden();
        $this->get($this->tenantUrl('alpha.test', '/staff/portfolio'))->assertOk();
        $this->get($this->tenantUrl('beta.test', '/staff/bookings/'.$booking->id))->assertNotFound();

        $this->post($this->tenantUrl('alpha.test', '/staff/switch/'.$beta['tenant']->id))
            ->assertRedirect();

        $stranger = $this->workspace('stranger.test');
        $this->post($this->tenantUrl('alpha.test', '/staff/switch/'.$stranger['tenant']->id))
            ->assertNotFound();
    }

    /**
     * The array session keeps its handler payload, and the guard keeps the
     * signed-in user for this process. Clear both so the next request is signed out.
     */
    private function dropStaffSession(): void
    {
        $this->flushSession();
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
    }

    private function booking(Tenant $tenant): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'operational_status' => 'not_arrived',
            'starts_at' => '2026-12-01 15:00:00',
            'ends_at' => '2026-12-01 16:00:00',
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }
}
