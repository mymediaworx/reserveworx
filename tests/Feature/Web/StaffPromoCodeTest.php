<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Setup\PromoCodes;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\PromoCode;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffPromoCodeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    public function test_an_owner_creates_edits_and_deactivates_a_promo_code(): void
    {
        $workspace = $this->signedIn();

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('/staff/promo-codes', false);

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(PromoCodes::class)
            ->set('code', 'summer')
            ->set('discountType', 'percent')
            ->set('discountValue', '10')
            ->set('startsOn', '2026-06-01')
            ->set('endsOn', '2026-08-31')
            ->set('usageLimit', '5')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('SUMMER');

        $promo = PromoCode::query()->where('code', 'SUMMER')->firstOrFail();
        $this->assertSame(10, $promo->percent_off);
        $this->assertNull($promo->amount_off_cents);
        $this->assertSame('2026-06-01', $promo->starts_on?->toDateString());
        $this->assertSame('2026-08-31', $promo->ends_on?->toDateString());
        $this->assertSame(5, $promo->usage_limit);
        $this->assertSame(0, $promo->redeemed_count);
        $this->assertTrue($promo->active);

        Livewire::test(PromoCodes::class)
            ->set('code', 'SUMMER')
            ->call('create')
            ->assertHasErrors('code');

        Livewire::test(PromoCodes::class)
            ->set('code', 'BACKWARDS')
            ->set('startsOn', '2026-08-01')
            ->set('endsOn', '2026-07-01')
            ->call('create')
            ->assertHasErrors('endsOn');

        Livewire::test(PromoCodes::class)
            ->call('edit', $promo->id)
            ->set('code', 'FALL')
            ->set('discountType', 'amount')
            ->set('discountValue', '15.00')
            ->set('startsOn', '')
            ->set('endsOn', '')
            ->set('usageLimit', '2')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('FALL')
            ->assertSee('$15.00 off');

        $promo->refresh();
        $this->assertSame('FALL', $promo->code);
        $this->assertNull($promo->percent_off);
        $this->assertSame(1500, $promo->amount_off_cents);
        $this->assertNull($promo->starts_on);
        $this->assertNull($promo->ends_on);
        $this->assertSame(2, $promo->usage_limit);
        $this->assertTrue($promo->active);

        Livewire::test(PromoCodes::class)
            ->call('deactivate', $promo->id)
            ->assertSee('Off');

        $this->assertFalse($promo->fresh()?->active);
        $logs = AuditLog::withoutGlobalScope(TenantScope::class);
        $this->assertSame(1, (clone $logs)->where('action', 'promo_code.created')->count());
        $this->assertSame(1, (clone $logs)->where('action', 'promo_code.updated')->count());
        $this->assertSame(1, (clone $logs)->where('action', 'promo_code.deactivated')->count());
    }

    public function test_staff_can_read_promo_codes_but_not_change_them(): void
    {
        $workspace = $this->signedIn('staff');
        PromoCode::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'code' => 'SAVE10',
            'percent_off' => 10,
            'active' => true,
        ]);

        $this->get($this->tenantUrl('alpha.test', '/staff/promo-codes'))
            ->assertOk()
            ->assertSee('SAVE10')
            ->assertDontSee('Add a promo code');

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(PromoCodes::class)
            ->set('code', 'SNEAKY')
            ->set('discountValue', '10')
            ->call('create')
            ->assertForbidden();

        Livewire::test(PromoCodes::class)
            ->call('deactivate', (string) PromoCode::query()->where('code', 'SAVE10')->value('id'))
            ->assertForbidden();

        $this->assertNull(PromoCode::query()->where('code', 'SNEAKY')->first());
        $this->assertTrue(PromoCode::query()->where('code', 'SAVE10')->firstOrFail()->active);
    }

    public function test_a_promo_code_from_another_workspace_is_not_listed(): void
    {
        $workspace = $this->signedIn();
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $hidden = PromoCode::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'code' => 'BETA10',
            'percent_off' => 10,
            'active' => true,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        $this->get($this->tenantUrl('alpha.test', '/staff/promo-codes'))
            ->assertOk()
            ->assertDontSee('BETA10');

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(PromoCodes::class)
            ->call('edit', $hidden->id)
            ->assertNotFound();
    }
}
