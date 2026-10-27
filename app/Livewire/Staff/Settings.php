<?php

namespace App\Livewire\Staff;

use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Enums\FeePayer;
use App\Enums\TransactionalEmail;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\StripeAccount;
use App\Models\TenantNotificationSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Booking\CancellationPolicies;
use App\Services\Branding\WorkspaceProfiles;
use App\Services\Onboarding\SetupGuide;
use App\Support\DisplayZone;
use App\Tenancy\Access;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Settings')]
class Settings extends Component
{
    use WithinTenant;

    public string $feePayer = 'customer_paid';

    public int $holdMinutes = 15;

    public int $ledgerBucketMinutes = 15;

    public string $timezone = TenantSetting::DEFAULT_TIMEZONE;

    public bool $emailPaymentReceipt = false;

    public bool $guestCancellationEnabled = false;

    public string $guestCancelMinutes = '';

    /**
     * Minutes before a confirmed booking starts. An empty list turns reminders off.
     *
     * @var list<string>
     */
    public array $reminderOffsets = [];

    public bool $remindersAreDefault = true;

    /**
     * On/off for the customer messages this page controls, in messageChoices() order.
     *
     * @var list<bool>
     */
    public array $messageEnabled = [];

    public string $stripeName = '';

    public string $stripeEmail = '';

    public ?string $flash = null;

    public ?string $problem = null;

    /**
     * @var list<array{lead_minutes: string, refund_percent: string}>
     */
    public array $cancelTiers = [];

    public string $noShowPercent = '';

    public function mount(CancellationPolicies $policies): void
    {
        $setting = $this->setting();
        $this->authorize('view', $setting);
        $this->feePayer = $setting->fee_payer->value;
        $this->holdMinutes = $setting->hold_minutes;
        $this->ledgerBucketMinutes = $setting->ledger_bucket_minutes;
        $this->timezone = DisplayZone::named($setting->timezone) ?? TenantSetting::DEFAULT_TIMEZONE;
        $this->emailPaymentReceipt = $setting->email_payment_receipt;
        $this->loadGuestCancellation($setting);
        $this->loadReminders($setting);
        $this->loadMessages();
        $this->stripeName = $this->tenant()->name;
        $this->stripeEmail = (string) auth()->user()?->email;
        $this->loadPolicy($policies);
    }

    public function save(): void
    {
        $setting = $this->setting();
        $this->authorize('update', $setting);
        $this->validate([
            'feePayer' => ['required', Rule::enum(FeePayer::class)],
            'holdMinutes' => ['required', 'integer', 'between:5,120'],
        ]);
        $before = $setting->only(['fee_payer', 'hold_minutes']);
        $setting->fee_payer = FeePayer::from($this->feePayer);
        $setting->hold_minutes = $this->holdMinutes;
        $setting->save();
        $this->audit('settings.updated', $setting, [
            'before' => ['fee_payer' => $before['fee_payer']->value, 'hold_minutes' => $before['hold_minutes']],
            'after' => ['fee_payer' => $setting->fee_payer->value, 'hold_minutes' => $setting->hold_minutes],
        ]);
        $this->flash = 'Settings saved. A change to who pays the booking fee applies to new bookings only.';
        $this->problem = null;
    }

    public function saveLedgerBucket(): void
    {
        $setting = $this->setting();
        $this->authorize('update', $setting);
        $this->validate([
            'ledgerBucketMinutes' => ['required', 'integer', Rule::in(TenantSetting::LEDGER_BUCKET_MINUTES)],
        ]);
        $before = $setting->ledger_bucket_minutes;
        $setting->ledger_bucket_minutes = $this->ledgerBucketMinutes;
        $setting->save();
        $this->audit('settings.updated', $setting, [
            'before' => ['ledger_bucket_minutes' => $before],
            'after' => ['ledger_bucket_minutes' => $setting->ledger_bucket_minutes],
        ]);
        $this->flash = 'Shared-gear lock saved. The next booking uses this block length. A booking already held keeps the blocks it used.';
        $this->problem = null;
    }

    public function saveTimezone(): void
    {
        $setting = $this->setting();
        $this->authorize('update', $setting);
        $this->validate([
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);
        $before = $setting->timezone;
        $setting->timezone = $this->timezone;
        $setting->save();
        $this->audit('settings.updated', $setting, [
            'before' => ['timezone' => $before],
            'after' => ['timezone' => $setting->timezone],
        ]);
        $this->flash = 'Business time zone saved. New locations start in this zone. A location that already has its own zone keeps that zone.';
        $this->problem = null;
    }

    public function savePaymentReceipt(): void
    {
        $setting = $this->setting();
        $this->authorize('update', $setting);
        $this->validate([
            'emailPaymentReceipt' => ['boolean'],
        ]);
        $before = $setting->email_payment_receipt;
        $setting->email_payment_receipt = $this->emailPaymentReceipt;
        $setting->save();
        $this->audit('settings.updated', $setting, [
            'before' => ['email_payment_receipt' => $before],
            'after' => ['email_payment_receipt' => $setting->email_payment_receipt],
        ]);
        $this->flash = 'Payment receipt email saved.';
        $this->problem = null;
    }

    public function saveGuestCancellation(): void
    {
        $setting = $this->setting();
        $this->authorize('update', $setting);
        $minutes = $this->parsedGuestCancelMinutes();
        $before = $setting->guest_cancel_before_minutes;
        $setting->guest_cancel_before_minutes = $minutes;
        $setting->save();
        $this->loadGuestCancellation($setting);
        $this->audit('settings.updated', $setting, [
            'before' => ['guest_cancel_before_minutes' => $before],
            'after' => ['guest_cancel_before_minutes' => $setting->guest_cancel_before_minutes],
        ]);
        $this->flash = $minutes === null
            ? 'Guest cancellation is off. A refund, a price, and a payment stay as they were.'
            : 'Guest cancellation saved. A refund, a price, and a payment stay as they were.';
        $this->problem = null;
    }

    public function addReminder(): void
    {
        $this->authorize('manageReminders', $this->setting());

        if (count($this->reminderOffsets) >= 8) {
            return;
        }

        $this->reminderOffsets[] = '';
    }

    public function removeReminder(int $index): void
    {
        $this->authorize('manageReminders', $this->setting());
        unset($this->reminderOffsets[$index]);
        $this->reminderOffsets = array_values($this->reminderOffsets);
    }

    public function saveReminders(): void
    {
        $setting = $this->setting();
        $this->authorize('manageReminders', $setting);
        $parsed = $this->parsedReminders();
        rsort($parsed);
        $before = $setting->reminder_offsets_minutes === null
            ? null
            : $setting->reminderOffsets();
        $setting->reminder_offsets_minutes = $parsed;
        $setting->save();
        $this->audit('reminders.updated', $setting, [
            'before' => $before,
            'after' => $setting->reminderOffsets(),
        ]);
        $this->loadReminders($setting->fresh() ?? $setting);
        $this->flash = $parsed === []
            ? 'Reminders are off. A booking that was already reminded is unchanged.'
            : 'Reminders saved. They apply the next time a reminder is due.';
        $this->problem = null;
    }

    public function saveMessages(WorkspaceProfiles $profiles): void
    {
        $setting = $profiles->notificationSettings();
        $this->authorize('update', $setting);
        $this->validate([
            'messageEnabled' => ['array'],
            'messageEnabled.*' => ['boolean'],
        ]);
        $before = $setting->auditSnapshot();
        $preferences = $setting->preferences();

        foreach (self::messageChoices() as $index => $choice) {
            $preferences[$choice['kind']] = (bool) ($this->messageEnabled[$index] ?? false);
        }

        $setting->transactional_emails = $preferences;
        $setting->save();
        $this->audit('notification_settings.updated', $setting, [
            'before' => $before,
            'after' => $setting->fresh()?->auditSnapshot() ?? $setting->auditSnapshot(),
        ]);
        $this->loadMessages();
        $this->flash = 'Customer messages saved. A price, a payment, and a refund stay as they were.';
        $this->problem = null;
    }

    public function connectStripe(StripeGateway $stripe): void
    {
        $this->authorize('create', Booking::class);
        $this->authorize('update', $this->setting());
        $this->validate([
            'stripeName' => ['required', 'string', 'max:120'],
            'stripeEmail' => ['required', 'email', 'max:255'],
        ]);
        $tenant = $this->tenant();
        $account = StripeAccount::query()->where('tenant_id', $tenant->id)->first();
        $back = url('/staff/settings');

        try {
            if (! $account instanceof StripeAccount) {
                $created = $stripe->createMerchantAccount(ConnectAccountSpec::merchant($tenant->id, $this->stripeName, $this->stripeEmail));
                $account = StripeAccount::query()->create([
                    'tenant_id' => $tenant->id,
                    'stripe_account_id' => $created['id'],
                    'card_payments_status' => $created['card_payments_status'],
                    'livemode' => $created['livemode'],
                ]);
            }

            $link = $stripe->createOnboardingLink(ConnectAccountSpec::onboardingLink($account->stripe_account_id, $back, $back));
        } catch (StripeNotConfiguredException $exception) {
            $this->problem = 'Card payments are not set up on this server yet. Ask the platform team to add the Stripe keys.';
            $this->flash = null;

            return;
        }

        $this->audit('stripe.account_linked', $account);
        $this->redirect($link['url']);
    }

    public function addCancelTier(): void
    {
        $this->authorize('update', $this->setting());

        if (count($this->cancelTiers) >= 8) {
            return;
        }

        $this->cancelTiers[] = ['lead_minutes' => '', 'refund_percent' => ''];
    }

    public function removeCancelTier(int $index): void
    {
        $this->authorize('update', $this->setting());
        unset($this->cancelTiers[$index]);
        $this->cancelTiers = array_values($this->cancelTiers);

        if ($this->cancelTiers === []) {
            $this->cancelTiers = [['lead_minutes' => '', 'refund_percent' => '']];
        }
    }

    public function saveCancellationPolicy(CancellationPolicies $policies): void
    {
        $this->authorize('update', $this->setting());
        $rows = [];
        $rules = [
            'noShowPercent' => ['nullable', 'integer', 'between:0,100'],
        ];
        $data = [
            'noShowPercent' => trim($this->noShowPercent) === '' ? null : trim($this->noShowPercent),
        ];

        foreach ($this->cancelTiers as $index => $row) {
            $lead = trim((string) $row['lead_minutes']);
            $percent = trim((string) $row['refund_percent']);

            if ($lead === '' && $percent === '') {
                continue;
            }

            $rules['cancelTiers.'.$index.'.lead_minutes'] = ['required', 'integer', 'between:0,525600'];
            $rules['cancelTiers.'.$index.'.refund_percent'] = ['required', 'integer', 'between:0,100'];
            $data['cancelTiers'][$index] = [
                'lead_minutes' => $lead,
                'refund_percent' => $percent,
            ];
            $rows[] = [
                'lead_minutes' => (int) $lead,
                'refund_percent' => (int) $percent,
            ];
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $actor = auth()->user();

        try {
            $policies->replace(
                $rows,
                $data['noShowPercent'] === null ? null : (int) $data['noShowPercent'],
                $actor instanceof User ? $actor : null,
            );
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first() ?: 'That cancellation policy could not be saved.';

            throw ValidationException::withMessages(['cancelTiers' => $message]);
        }

        $this->loadPolicy($policies);
        $this->flash = 'Cancellation policy saved. It applies the next time a customer cancels or is marked as a no-show.';
        $this->problem = null;
    }

    public function render(): View
    {
        $setting = $this->setting();
        $account = StripeAccount::query()->where('tenant_id', $this->tenant()->id)->first();

        return view('livewire.staff.settings', [
            'setting' => $setting,
            'directRate' => FeeRate::fromTenantSetting($setting, FeeChannel::Direct),
            'account' => $account,
            'canUpdate' => $this->allows('settings.update'),
            'canManageReminders' => $this->currentUser()->can('manageReminders', $setting),
            'canUpdateMessages' => $this->currentUser()->can('update', $this->notificationSetting()),
            'messageChoices' => self::messageChoices(),
            'reminderHints' => array_map(fn (string $minutes): string => $this->reminderHint($minutes), $this->reminderOffsets),
            'timezones' => timezone_identifiers_list(),
            'ledgerBucketChoices' => TenantSetting::LEDGER_BUCKET_MINUTES,
            'canSeeSetupGuide' => $this->ownerOrManager(),
            'setupGuideHidden' => app(SetupGuide::class)->isHidden($this->tenant()),
        ]);
    }

    public function toggleSetupGuide(SetupGuide $guide): void
    {
        abort_unless($this->ownerOrManager(), 403);
        $hidden = ! $guide->isHidden($this->tenant());
        $guide->setHidden($this->tenant(), $hidden);
        $this->flash = $hidden ? 'Setup guide hidden.' : 'Setup guide turned back on.';
        $this->problem = null;
    }

    private function ownerOrManager(): bool
    {
        return app(Access::class)->hasRole($this->currentUser(), $this->tenant()->id, 'owner', 'manager');
    }

    private function loadPolicy(CancellationPolicies $policies): void
    {
        $tiers = $policies->customerTiers();
        $this->cancelTiers = $tiers === []
            ? [['lead_minutes' => '', 'refund_percent' => '']]
            : array_map(fn (array $tier): array => [
                'lead_minutes' => (string) $tier['lead_minutes'],
                'refund_percent' => (string) $tier['refund_percent'],
            ], $tiers);
        $noShow = $policies->noShowPercent();
        $this->noShowPercent = $noShow === null ? '' : (string) $noShow;
    }

    private function setting(): TenantSetting
    {
        $setting = $this->settings();
        abort_unless($setting instanceof TenantSetting, 404);

        return $setting;
    }

    private function notificationSetting(): TenantNotificationSetting
    {
        return app(WorkspaceProfiles::class)->notificationSettings();
    }

    private function loadGuestCancellation(TenantSetting $setting): void
    {
        $minutes = $setting->guest_cancel_before_minutes;
        $this->guestCancellationEnabled = $minutes !== null;
        $this->guestCancelMinutes = $minutes === null ? '' : (string) $minutes;
    }

    private function parsedGuestCancelMinutes(): ?int
    {
        if (! $this->guestCancellationEnabled) {
            return null;
        }

        $trimmed = trim((string) $this->guestCancelMinutes);
        $validator = Validator::make(
            ['guestCancelMinutes' => $trimmed],
            ['guestCancelMinutes' => ['required', 'integer', 'between:0,43200']],
            [
                'guestCancelMinutes.required' => 'Enter how many minutes before the start a guest can still cancel.',
                'guestCancelMinutes.integer' => 'Enter whole minutes from 0 to 43,200.',
                'guestCancelMinutes.between' => 'Enter whole minutes from 0 to 43,200.',
            ],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return (int) $trimmed;
    }

    private function loadReminders(TenantSetting $setting): void
    {
        $this->remindersAreDefault = $setting->reminder_offsets_minutes === null;
        $offsets = $setting->reminderOffsets();
        rsort($offsets);
        $this->reminderOffsets = array_map(static fn (int $minutes): string => (string) $minutes, $offsets);
    }

    private function loadMessages(): void
    {
        $preferences = $this->notificationSetting()->preferences();
        $enabled = [];

        foreach (self::messageChoices() as $choice) {
            $enabled[] = $preferences[$choice['kind']] ?? true;
        }

        $this->messageEnabled = $enabled;
    }

    /**
     * @return list<int>
     */
    private function parsedReminders(): array
    {
        $rules = [
            'reminderOffsets' => ['array', 'max:8'],
        ];
        $data = [
            'reminderOffsets' => $this->reminderOffsets,
        ];
        $parsed = [];

        foreach ($this->reminderOffsets as $index => $minutes) {
            $trimmed = trim((string) $minutes);

            if ($trimmed === '') {
                continue;
            }

            $rules['reminderOffsets.'.$index] = ['required', 'integer', 'between:1,10080'];
            $data['reminderOffsets'][$index] = $trimmed;
            $parsed[] = (int) $trimmed;
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (count($parsed) !== count(array_unique($parsed))) {
            throw ValidationException::withMessages([
                'reminderOffsets' => 'Each reminder needs its own number of minutes.',
            ]);
        }

        return $parsed;
    }

    private function reminderHint(string $minutes): string
    {
        if (preg_match('/^\d+$/', $minutes) !== 1) {
            return '';
        }

        $value = (int) $minutes;

        if ($value % 1440 === 0 && $value >= 1440) {
            $days = intdiv($value, 1440);

            return $days === 1 ? '1 day before the start' : $days.' days before the start';
        }

        if ($value > 0 && $value % 60 === 0) {
            $hours = intdiv($value, 60);

            return $hours === 1 ? '1 hour before the start' : $hours.' hours before the start';
        }

        if ($value === 1) {
            return '1 minute before the start';
        }

        return $value > 0 ? $value.' minutes before the start' : '';
    }

    /**
     * Customer messages this page can turn on or off.
     * Feedback stays on the feedback page. The payment receipt stays on its own switch.
     * Check-out and return are recorded and are not emailed from this queue.
     *
     * @return list<array{kind: string, label: string}>
     */
    public static function messageChoices(): array
    {
        return [
            ['kind' => TransactionalEmail::BookingConfirmed->value, 'label' => 'Confirmation'],
            ['kind' => TransactionalEmail::BookingReminder->value, 'label' => 'Reminder'],
            ['kind' => TransactionalEmail::BookingCancelled->value, 'label' => 'Cancellation'],
            ['kind' => TransactionalEmail::BookingGuestCancelled->value, 'label' => 'Guest cancellation'],
            ['kind' => TransactionalEmail::BookingGuestRescheduled->value, 'label' => 'New time'],
            ['kind' => TransactionalEmail::PaymentFailed->value, 'label' => 'Payment failed'],
            ['kind' => TransactionalEmail::RefundIssued->value, 'label' => 'Refund'],
            ['kind' => TransactionalEmail::RentalOverdue->value, 'label' => 'Overdue rental'],
            ['kind' => TransactionalEmail::WaitlistJoined->value, 'label' => 'Waitlist joined'],
            ['kind' => TransactionalEmail::WaitlistPromoted->value, 'label' => 'Waitlist opening'],
        ];
    }
}
