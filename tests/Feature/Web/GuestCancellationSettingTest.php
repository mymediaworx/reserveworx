<?php

namespace Tests\Feature\Web;

use App\Enums\CancellationPolicyKind;
use App\Livewire\Staff\Settings;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestCancellationSettingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_owner_saves_how_far_ahead_a_guest_may_cancel_without_changing_money(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $this->assertNull($setting->guest_cancel_before_minutes);
        $this->assertSame(15, $setting->ledger_bucket_minutes);
        $before = $this->unchangedColumns($setting);

        $this->useTenant($owner['tenant']);
        CancellationPolicy::query()->create([
            'tenant_id' => $owner['tenant']->id,
            'kind' => CancellationPolicyKind::CustomerCancel,
            'lead_minutes' => 1440,
            'refund_percent' => 50,
        ]);

        $page = $this->get($this->tenantUrl('alpha.test', '/staff/settings'));
        $page->assertOk()
            ->assertSee('id="guest-cancellation"', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('Online cancellation is off.')
            ->assertSee('Save guest cancellation')
            ->assertSee('This does not change a cancellation window or a refund.');

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('guestCancellationEnabled', false)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '120')
            ->call('saveGuestCancellation')
            ->assertHasNoErrors()
            ->assertSee('Guest cancellation saved.');

        $setting->refresh();
        $this->assertSame(120, $setting->guest_cancel_before_minutes);
        $this->assertSame($before, $this->unchangedColumns($setting));
        $this->assertSame(15, $setting->ledger_bucket_minutes);
        $policy = CancellationPolicy::query()->firstOrFail();
        $this->assertSame(CancellationPolicyKind::CustomerCancel, $policy->kind);
        $this->assertSame(1440, $policy->lead_minutes);
        $this->assertSame(50, $policy->refund_percent);
        $this->assertSame(1, CancellationPolicy::query()->count());
        $betaSetting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->firstOrFail();
        $this->assertNull($betaSetting->guest_cancel_before_minutes);

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertNull($audit->properties['before']['guest_cancel_before_minutes'] ?? null);
        $this->assertSame(120, $audit->properties['after']['guest_cancel_before_minutes'] ?? null);
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
        $this->assertMoneyUntouched();

        $manager = $this->signedIn('manager.test', 'manager');
        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '30')
            ->call('saveGuestCancellation')
            ->assertHasNoErrors();
        $saved = TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->firstOrFail();
        $this->assertSame(30, $saved->guest_cancel_before_minutes);
        $ownerSetting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $this->assertSame(120, $ownerSetting->guest_cancel_before_minutes);
    }

    public function test_a_bad_window_is_refused_and_turning_it_off_clears_it(): void
    {
        $owner = $this->signedIn('alpha.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '43201')
            ->call('saveGuestCancellation')
            ->assertHasErrors(['guestCancelMinutes'])
            ->assertSee('Enter whole minutes from 0 to 43,200.');

        $setting->refresh();
        $this->assertNull($setting->guest_cancel_before_minutes);
        $this->assertSame(0, AuditLog::query()->count());

        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '')
            ->call('saveGuestCancellation')
            ->assertHasErrors(['guestCancelMinutes']);

        $setting->refresh();
        $this->assertNull($setting->guest_cancel_before_minutes);

        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '0')
            ->call('saveGuestCancellation')
            ->assertHasNoErrors()
            ->assertSee('Guests can cancel until the start.');

        $setting->refresh();
        $this->assertSame(0, $setting->guest_cancel_before_minutes);

        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', false)
            ->set('guestCancelMinutes', '15')
            ->call('saveGuestCancellation')
            ->assertHasNoErrors()
            ->assertSee('Guest cancellation is off.');

        $setting->refresh();
        $this->assertNull($setting->guest_cancel_before_minutes);
        $cleared = AuditLog::query()->where('action', 'settings.updated')->orderByDesc('id')->firstOrFail();
        $this->assertSame(0, $cleared->properties['before']['guest_cancel_before_minutes']);
        $this->assertNull($cleared->properties['after']['guest_cancel_before_minutes']);
        $this->assertSame(0, CancellationPolicy::query()->count());
        $this->assertMoneyUntouched();
    }

    public function test_staff_can_see_the_window_and_cannot_save_it(): void
    {
        $staff = $this->signedIn('staff.test', 'staff');
        $setting = TenantSetting::query()->where('tenant_id', $staff['tenant']->id)->firstOrFail();

        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Online cancellation is off.')
            ->assertDontSee('Save guest cancellation');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '90')
            ->call('saveGuestCancellation')
            ->assertForbidden();

        $setting->refresh();
        $this->assertNull($setting->guest_cancel_before_minutes);
        $this->assertSame(0, AuditLog::query()->count());

        $setting->guest_cancel_before_minutes = 90;
        $setting->save();

        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Guests can cancel until 90 minutes before the start.')
            ->assertDontSee('Save guest cancellation');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('guestCancellationEnabled', true)
            ->assertSet('guestCancelMinutes', '90')
            ->set('guestCancelMinutes', '10')
            ->call('saveGuestCancellation')
            ->assertForbidden();

        $setting->refresh();
        $this->assertSame(90, $setting->guest_cancel_before_minutes);
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertMoneyUntouched();
    }

    public function test_a_saved_window_lets_a_guest_cancel_outside_it_and_does_not_refund(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $owner = $this->signedIn('dock.test');
        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('guestCancellationEnabled', true)
            ->set('guestCancelMinutes', '60')
            ->call('saveGuestCancellation')
            ->assertHasNoErrors();

        $open = $this->heldBooking($owner['tenant'], '2026-06-15 15:00:00', 'ada@example.com');
        $soon = $this->heldBooking($owner['tenant'], '2026-06-15 12:30:00', 'ada@example.com');
        app(TenantManager::class)->forget();
        $token = app(WidgetTokens::class)->hostedPlain($owner['tenant']);

        $openManage = $this->manageUrl($token, $open, 'ada@example.com');
        $openPage = $this->get($openManage)->assertOk();
        $openPage->assertSee('Cancel booking');
        preg_match('/action="([^"]*cancel[^"]*)"/', (string) $openPage->getContent(), $cancel);
        $this->assertArrayHasKey(1, $cancel);
        $this->post(html_entity_decode($cancel[1]))->assertOk()->assertSee('Status: Cancelled');

        $this->useTenant($owner['tenant']);
        $this->assertSame('cancelled', $open->fresh()?->status->value);
        app(TenantManager::class)->forget();

        $soonPage = $this->get($this->manageUrl($token, $soon, 'ada@example.com'))->assertOk();
        $soonPage->assertSee('This booking is inside the cancellation window.');
        $soonPage->assertDontSee('Cancel booking');

        $this->assertSame(0, CancellationPolicy::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, LocationTaxRule::withoutGlobalScope(TenantScope::class)->count());
    }

    private function manageUrl(string $token, Booking $booking, string $email): string
    {
        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $booking->id,
            'email' => $email,
        ])->assertRedirect();

        return (string) $found->headers->get('Location');
    }

    private function heldBooking(Tenant $tenant, string $startsAt, string $email): Booking
    {
        $this->useTenant($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat '.$startsAt,
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => 'held',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour(),
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unchangedColumns(TenantSetting $setting): array
    {
        return $setting->only([
            'fee_payer',
            'hold_minutes',
            'direct_fee_bps',
            'third_party_fee_bps',
            'pay_at_pickup',
            'timezone',
            'email_payment_receipt',
            'late_fee_per_minute_cents',
            'late_grace_minutes',
            'ledger_bucket_minutes',
            'reminder_offsets_minutes',
        ]);
    }

    private function assertMoneyUntouched(): void
    {
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, LocationTaxRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Booking::withoutGlobalScope(TenantScope::class)->count());
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
