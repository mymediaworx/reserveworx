<?php

namespace Tests\Feature\Web;

use App\Enums\TransactionalEmail;
use App\Livewire\Staff\Settings;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Notifications\TransactionalEmailPreferences;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerMessageSettingsTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_an_owner_sees_the_usual_reminders_and_customer_messages(): void
    {
        $owner = $this->signedIn('alpha.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $this->assertNull($setting->reminder_offsets_minutes);

        $this->get($this->tenantUrl('alpha.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('id="reminders"', false)
            ->assertSee('id="customer-messages"', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('These are the usual times')
            ->assertSee('1 day before the start')
            ->assertSee('2 hours before the start')
            ->assertSee('Save reminders')
            ->assertSee('Customer messages')
            ->assertSee('Feedback stays on the feedback page')
            ->assertSee('Save customer messages');

        foreach (Settings::messageChoices() as $choice) {
            $this->assertNotSame('', $choice['label']);
        }

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('remindersAreDefault', true)
            ->assertSet('reminderOffsets', ['1440', '120'])
            ->assertSet('messageEnabled', array_fill(0, count(Settings::messageChoices()), true))
            ->assertSee('Confirmation')
            ->assertSee('Waitlist opening');

        $setting->refresh();
        $this->assertNull($setting->reminder_offsets_minutes);
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['reminders.updated', 'notification_settings.updated'])->count());
        $this->assertMoneyUntouched();
    }

    public function test_an_owner_or_manager_saves_reminder_times_without_changing_money(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $before = $this->moneyColumns($setting);

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['', '60'])
            ->call('saveReminders')
            ->assertHasNoErrors()
            ->assertSee('Reminders saved.');

        $setting->refresh();
        $this->assertSame([60], $setting->reminder_offsets_minutes);
        $this->assertSame([60], $setting->reminderOffsets());
        $this->assertSame($before, $this->moneyColumns($setting));
        $betaSetting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->firstOrFail();
        $this->assertNull($betaSetting->reminder_offsets_minutes);

        $audit = AuditLog::query()->where('action', 'reminders.updated')->firstOrFail();
        $this->assertNull($audit->properties['before'] ?? null);
        $this->assertSame([60], $audit->properties['after'] ?? null);
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
        $this->assertMoneyUntouched();

        $manager = $this->signedIn('manager.test', 'manager');
        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['30', '90'])
            ->call('saveReminders')
            ->assertHasNoErrors();
        $saved = TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->firstOrFail();
        $this->assertSame([90, 30], $saved->reminder_offsets_minutes);
        $ownerSetting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $this->assertSame([60], $ownerSetting->reminder_offsets_minutes);
    }

    public function test_an_empty_list_turns_reminders_off_and_bad_times_are_refused(): void
    {
        $owner = $this->signedIn('alpha.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('reminderOffsets', [])
            ->call('saveReminders')
            ->assertHasNoErrors()
            ->assertSee('Reminders are off.');

        $setting->refresh();
        $this->assertSame([], $setting->reminder_offsets_minutes);
        $this->assertSame([], $setting->reminderOffsets());

        Livewire::test(Settings::class)
            ->assertSet('remindersAreDefault', false)
            ->assertSet('reminderOffsets', [])
            ->set('reminderOffsets', ['60', '60'])
            ->call('saveReminders')
            ->assertHasErrors(['reminderOffsets' => 'Each reminder needs its own number of minutes.']);

        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['0'])
            ->call('saveReminders')
            ->assertHasErrors('reminderOffsets.0');

        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['10081'])
            ->call('saveReminders')
            ->assertHasErrors('reminderOffsets.0');

        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['1', '2', '3', '4', '5', '6', '7', '8', '9'])
            ->call('saveReminders')
            ->assertHasErrors('reminderOffsets');

        $setting->refresh();
        $this->assertSame([], $setting->reminder_offsets_minutes);
        $this->assertMoneyUntouched();
    }

    public function test_staff_can_read_both_lists_and_cannot_change_them(): void
    {
        $staff = $this->signedIn('staff.test', 'staff');
        $setting = TenantSetting::query()->where('tenant_id', $staff['tenant']->id)->firstOrFail();
        $messages = $this->notificationRow($staff['tenant']);
        $emails = $messages->transactional_emails;

        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Reminders')
            ->assertSee('Customer messages')
            ->assertSee('Confirmation')
            ->assertDontSee('Save reminders')
            ->assertDontSee('Save customer messages')
            ->assertDontSee('Add a reminder');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->set('reminderOffsets', ['15'])
            ->call('saveReminders')
            ->assertForbidden();

        Livewire::test(Settings::class)
            ->call('addReminder')
            ->assertForbidden();

        Livewire::test(Settings::class)
            ->call('removeReminder', 0)
            ->assertForbidden();

        Livewire::test(Settings::class)
            ->set('messageEnabled.1', false)
            ->call('saveMessages')
            ->assertForbidden();

        $setting->refresh();
        $this->assertNull($setting->reminder_offsets_minutes);
        $messages->refresh();
        $this->assertSame($emails, $messages->transactional_emails);
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertMoneyUntouched();
    }

    public function test_turning_off_a_customer_message_leaves_feedback_receipt_and_other_workspaces(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $before = $this->moneyColumns($setting);
        $betaEmails = $this->notificationRow($beta['tenant'])->transactional_emails;
        $reminderIndex = $this->choiceIndex(TransactionalEmail::BookingReminder->value);

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('messageEnabled.'.$reminderIndex, false)
            ->call('saveMessages')
            ->assertHasNoErrors()
            ->assertSee('Customer messages saved.');

        $preferences = app(TransactionalEmailPreferences::class);
        $this->assertFalse($preferences->enabled($owner['tenant']->id, TransactionalEmail::BookingReminder->value));
        $this->assertTrue($preferences->enabled($owner['tenant']->id, TransactionalEmail::BookingFeedback->value));
        $this->assertTrue($preferences->enabled($owner['tenant']->id, TransactionalEmail::PaymentCompleted->value));
        $this->assertTrue($preferences->enabled($owner['tenant']->id, TransactionalEmail::BookingCheckedOut->value));
        $this->assertTrue($preferences->enabled($owner['tenant']->id, TransactionalEmail::BookingReturned->value));
        $this->assertTrue($preferences->enabled($owner['tenant']->id, TransactionalEmail::BookingConfirmed->value));

        $stored = $this->notificationRow($owner['tenant'])->preferences();
        $this->assertFalse($stored[TransactionalEmail::BookingReminder->value]);
        $this->assertTrue($stored[TransactionalEmail::BookingFeedback->value]);

        $setting->refresh();
        $this->assertSame($before, $this->moneyColumns($setting));
        $this->assertFalse($setting->email_payment_receipt);
        $this->assertSame($betaEmails, $this->notificationRow($beta['tenant'])->transactional_emails);
        $this->assertTrue($preferences->enabled($beta['tenant']->id, TransactionalEmail::BookingReminder->value));

        $audit = AuditLog::query()->where('action', 'notification_settings.updated')->firstOrFail();
        $this->assertTrue($audit->properties['before']['transactional_emails'][TransactionalEmail::BookingReminder->value] ?? false);
        $this->assertFalse($audit->properties['after']['transactional_emails'][TransactionalEmail::BookingReminder->value] ?? true);
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
        $this->assertMoneyUntouched();

        $manager = $this->signedIn('manager.test', 'manager');
        $managerIndex = $this->choiceIndex(TransactionalEmail::WaitlistJoined->value);
        Livewire::test(Settings::class)
            ->set('messageEnabled.'.$managerIndex, false)
            ->call('saveMessages')
            ->assertHasNoErrors();
        $this->assertFalse(
            app(TransactionalEmailPreferences::class)->enabled($manager['tenant']->id, TransactionalEmail::WaitlistJoined->value),
        );
        $this->assertFalse(
            app(TransactionalEmailPreferences::class)->enabled($owner['tenant']->id, TransactionalEmail::BookingReminder->value),
        );
    }

    private function choiceIndex(string $kind): int
    {
        foreach (Settings::messageChoices() as $index => $choice) {
            if ($choice['kind'] === $kind) {
                return $index;
            }
        }

        $this->fail($kind.' is not a customer message on the settings page.');
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyColumns(TenantSetting $setting): array
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
            'guest_cancel_before_minutes',
        ]);
    }

    private function assertMoneyUntouched(): void
    {
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, LocationTaxRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Booking::withoutGlobalScope(TenantScope::class)->count());
    }

    private function notificationRow(Tenant $tenant): TenantNotificationSetting
    {
        return TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
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
