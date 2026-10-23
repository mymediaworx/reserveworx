<?php

namespace App\Livewire\Staff;

use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Enums\FeePayer;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\StripeAccount;
use App\Models\TenantSetting;
use Illuminate\Validation\Rule;
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

    public string $stripeName = '';

    public string $stripeEmail = '';

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $setting = $this->setting();
        $this->authorize('view', $setting);
        $this->feePayer = $setting->fee_payer->value;
        $this->holdMinutes = $setting->hold_minutes;
        $this->stripeName = $this->tenant()->name;
        $this->stripeEmail = (string) auth()->user()?->email;
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

    public function render(): View
    {
        $setting = $this->setting();
        $account = StripeAccount::query()->where('tenant_id', $this->tenant()->id)->first();

        return view('livewire.staff.settings', [
            'setting' => $setting,
            'directRate' => FeeRate::fromTenantSetting($setting, FeeChannel::Direct),
            'account' => $account,
            'canUpdate' => $this->allows('settings.update'),
        ]);
    }

    private function setting(): TenantSetting
    {
        $setting = $this->settings();
        abort_unless($setting instanceof TenantSetting, 404);

        return $setting;
    }
}
