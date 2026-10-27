<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Settings;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Platform\WorkspaceProvisioner;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PaymentReceiptSettingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_an_owner_or_manager_can_turn_payment_receipts_on_and_staff_cannot(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $this->assertFalse($setting->email_payment_receipt);
        $before = $setting->only(['fee_payer', 'hold_minutes', 'direct_fee_bps', 'pay_at_pickup', 'timezone']);

        $this->get($this->tenantUrl('alpha.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Email customers a payment receipt')
            ->assertSee('Save payment receipts');

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('emailPaymentReceipt', false)
            ->set('emailPaymentReceipt', true)
            ->call('savePaymentReceipt')
            ->assertHasNoErrors()
            ->assertSee('Payment receipt email saved.');

        $setting->refresh();
        $this->assertTrue($setting->email_payment_receipt);
        $this->assertSame($before['fee_payer'], $setting->fee_payer);
        $this->assertSame($before['hold_minutes'], $setting->hold_minutes);
        $this->assertSame($before['direct_fee_bps'], $setting->direct_fee_bps);
        $this->assertSame($before['pay_at_pickup'], $setting->pay_at_pickup);
        $this->assertSame($before['timezone'], $setting->timezone);

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(['email_payment_receipt' => false], $audit->properties['before'] ?? null);
        $this->assertSame(['email_payment_receipt' => true], $audit->properties['after'] ?? null);
        $this->assertFalse(
            (bool) TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->value('email_payment_receipt'),
        );

        $manager = $this->signedIn('manager.test', 'manager');
        Livewire::test(Settings::class)
            ->set('emailPaymentReceipt', true)
            ->call('savePaymentReceipt')
            ->assertHasNoErrors();
        $this->assertTrue((bool) TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->value('email_payment_receipt'));
        $this->assertTrue(
            (bool) TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $owner['tenant']->id)->value('email_payment_receipt'),
        );

        $this->useTenant($manager['tenant']);
        Livewire::test(Settings::class)
            ->set('emailPaymentReceipt', false)
            ->call('savePaymentReceipt')
            ->assertHasNoErrors();
        $this->assertFalse((bool) TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->value('email_payment_receipt'));

        $staff = $this->signedIn('staff.test', 'staff');
        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Email customers a payment receipt')
            ->assertDontSee('Save payment receipts');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->set('emailPaymentReceipt', true)
            ->call('savePaymentReceipt')
            ->assertForbidden();

        $this->useTenant($staff['tenant']);
        $this->assertFalse((bool) TenantSetting::query()->where('tenant_id', $staff['tenant']->id)->value('email_payment_receipt'));
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
    }

    public function test_a_new_workspace_leaves_payment_receipts_off(): void
    {
        $column = collect(Schema::getColumns('tenant_settings'))->firstWhere('name', 'email_payment_receipt');
        $this->assertIsArray($column);
        $this->assertNotNull($column['default']);
        $this->assertContains((string) $column['default'], ['0', 'false']);
        $this->assertFalse($column['nullable']);

        $names = collect(Schema::getColumns('tenant_settings'))->pluck('name');

        foreach (['fee_payer', 'direct_fee_bps', 'pay_at_pickup', 'timezone', 'currency'] as $name) {
            $this->assertTrue($names->contains($name), $name);
        }

        $workspace = $this->workspace('fresh.test');
        $setting = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->firstOrFail();
        $this->assertFalse($setting->email_payment_receipt);

        $created = app(WorkspaceProvisioner::class)->create('New Dock', 'new-dock-receipts', null, null);
        $provisioned = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $created->id)
            ->firstOrFail();
        $this->assertFalse($provisioned->email_payment_receipt);
        $this->assertSame(300, $provisioned->direct_fee_bps);
        $this->assertSame('customer_paid', $provisioned->fee_payer->value);
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    private function signedIn(string $host, string $role = 'owner'): array
    {
        $workspace = $this->workspace($host, $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
