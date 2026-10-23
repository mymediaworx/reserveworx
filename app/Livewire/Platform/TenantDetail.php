<?php

namespace App\Livewire\Platform;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantLifecycle;
use App\Support\Hostname;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One workspace: status, domains, booking fee rates, and owners. Each action
 * mirrors the platform API and writes the same audit action.
 */
#[Layout('layouts.platform')]
class TenantDetail extends Component
{
    #[Locked]
    public string $tenantId = '';

    public string $hostname = '';

    public string $domainType = 'subdomain';

    public bool $primary = true;

    public string $directPercent = '3';

    public string $thirdPartyPercent = '1';

    public string $directFixed = '0';

    public string $thirdPartyFixed = '0';

    public string $directMinimum = '';

    public string $thirdPartyMinimum = '';

    public string $ownerName = '';

    public string $ownerEmail = '';

    public ?string $newPassword = null;

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(string $tenant): void
    {
        $this->guard();
        $model = $this->tenant($tenant);
        $this->tenantId = $model->id;
        $setting = $this->setting();
        $this->directPercent = $this->percent($setting->direct_fee_bps);
        $this->thirdPartyPercent = $this->percent($setting->third_party_fee_bps);
        $this->directFixed = $this->dollars($setting->direct_fee_fixed_cents);
        $this->thirdPartyFixed = $this->dollars($setting->third_party_fee_fixed_cents);
        $this->directMinimum = $setting->direct_fee_minimum_cents === null ? '' : $this->dollars($setting->direct_fee_minimum_cents);
        $this->thirdPartyMinimum = $setting->third_party_fee_minimum_cents === null ? '' : $this->dollars($setting->third_party_fee_minimum_cents);
    }

    public function setStatus(string $status, TenantLifecycle $lifecycle, AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $tenant = $this->tenant($this->tenantId);
        $next = TenantStatus::tryFrom($status);
        abort_if($next === null, 422);

        if ($next === TenantStatus::Terminated) {
            $audit->log('platform.termination_refused', $tenant, ['reason' => 'data_export_not_available'], $tenant->id, $actor, request(), false);
            $this->problem = 'Closing a workspace for good is refused until a data export exists.';

            return;
        }

        if (! $lifecycle->canTransition($tenant->status, $next)) {
            $this->problem = 'That status change is not allowed.';

            return;
        }

        $before = $tenant->status->value;
        $tenant->status = $next;
        $tenant->save();
        $audit->log('tenant.status_changed', $tenant, ['before' => $before, 'after' => $next->value], $tenant->id, $actor, request(), false);
        $this->flash = 'Status changed to '.$next->value.'.';
    }

    public function addDomain(AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $tenant = $this->tenant($this->tenantId);
        $this->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'domainType' => ['required', Rule::in(['subdomain', 'custom'])],
        ]);
        $host = Hostname::normalize($this->hostname);

        if (! Hostname::isValid($host)) {
            $this->addError('hostname', 'Enter a hostname such as booking.example.com.');

            return;
        }

        if (in_array($host, config('reserveworx.platform_domains', []), true)) {
            $this->addError('hostname', 'That hostname is reserved for the platform.');

            return;
        }

        if (Domain::withoutGlobalScope(TenantScope::class)->where('hostname', $host)->exists()) {
            $this->addError('hostname', 'That hostname is already in use.');

            return;
        }

        DB::transaction(function () use ($tenant, $host, $audit, $actor): void {
            if ($this->primary) {
                Domain::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->update(['is_primary' => false]);
            }

            $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'hostname' => $host,
                'type' => $this->domainType,
                'is_primary' => $this->primary,
                'verified_at' => null,
            ]);
            $audit->log('domain.created', $domain, ['hostname' => $host, 'type' => $domain->type], $tenant->id, $actor, request(), false);
        });

        $this->reset('hostname');
        $this->flash = 'Domain added. Point its DNS at the platform, add SSL, then mark it verified.';
    }

    public function verifyDomain(string $id, AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $domain = Domain::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId)->whereKey($id)->firstOrFail();

        if ($domain->verified_at === null) {
            $domain->verified_at = now();
            $domain->save();
            $audit->log('domain.verified', $domain, ['hostname' => $domain->hostname, 'method' => 'platform_attestation'], $this->tenantId, $actor, request(), false);
        }

        $this->flash = $domain->hostname.' is verified. The workspace is now served there.';
    }

    public function saveFees(AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $money = ['required', 'numeric', 'min:0', 'max:10000'];
        $optional = ['nullable', 'numeric', 'min:0', 'max:10000'];
        $this->validate([
            'directPercent' => ['required', 'numeric', 'between:0,100'],
            'thirdPartyPercent' => ['required', 'numeric', 'between:0,100'],
            'directFixed' => $money,
            'thirdPartyFixed' => $money,
            'directMinimum' => $optional,
            'thirdPartyMinimum' => $optional,
        ]);
        $setting = $this->setting();
        $keys = ['direct_fee_bps', 'third_party_fee_bps', 'direct_fee_fixed_cents', 'third_party_fee_fixed_cents', 'direct_fee_minimum_cents', 'third_party_fee_minimum_cents'];
        $before = $setting->only($keys);
        $setting->fill([
            'direct_fee_bps' => (int) round(((float) $this->directPercent) * 100),
            'third_party_fee_bps' => (int) round(((float) $this->thirdPartyPercent) * 100),
            'direct_fee_fixed_cents' => (int) round(((float) $this->directFixed) * 100),
            'third_party_fee_fixed_cents' => (int) round(((float) $this->thirdPartyFixed) * 100),
            'direct_fee_minimum_cents' => $this->directMinimum === '' ? null : (int) round(((float) $this->directMinimum) * 100),
            'third_party_fee_minimum_cents' => $this->thirdPartyMinimum === '' ? null : (int) round(((float) $this->thirdPartyMinimum) * 100),
        ])->save();
        $audit->log('platform.fees_updated', $setting, ['before' => $before, 'after' => $setting->only($keys)], $this->tenantId, $actor, request(), false);
        $this->flash = 'Booking fee rates saved. They apply to bookings priced from now on.';
    }

    public function addOwner(AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $this->validate([
            'ownerName' => ['required', 'string', 'max:120'],
            'ownerEmail' => ['required', 'email', 'max:255'],
        ]);
        $email = strtolower($this->ownerEmail);
        $role = Role::query()->where('slug', 'owner')->firstOrFail();
        $password = null;

        DB::transaction(function () use ($email, $role, &$password, $audit, $actor): void {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                $password = Str::password(16, symbols: false);
                $user = User::query()->create(['name' => $this->ownerName, 'email' => $email, 'password' => $password]);
            }

            $membership = Membership::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $this->tenantId)
                ->where('user_id', $user->id)
                ->first();

            if ($membership instanceof Membership) {
                $membership->fill(['role_id' => $role->id, 'status' => MembershipStatus::Active])->save();
            } else {
                $membership = Membership::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id' => $this->tenantId,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'status' => MembershipStatus::Active,
                ]);
            }

            $audit->log('member.created', $membership, ['role' => 'owner', 'by' => 'platform'], $this->tenantId, $actor, request(), false);
        });

        $this->newPassword = $password;
        $this->reset('ownerName', 'ownerEmail');
        $this->flash = $password === null
            ? 'Owner added. They already had an account and sign in with their existing password.'
            : 'Owner added. Give them the temporary password below; it is shown only once.';
    }

    public function render(): View
    {
        $tenant = $this->tenant($this->tenantId);
        $members = Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->with(['user', 'role'])
            ->get();

        return view('livewire.platform.tenant-detail', [
            'tenant' => $tenant,
            'domains' => Domain::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->orderByDesc('is_primary')->orderBy('hostname')->get(),
            'members' => $members,
            'statuses' => TenantStatus::cases(),
            'setting' => $this->setting(),
        ])->title($tenant->name);
    }

    private function guard(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }

    private function tenant(string $id): Tenant
    {
        $tenant = Tenant::query()->find($id);
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function setting(): TenantSetting
    {
        return TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId)->firstOrFail();
    }

    private function resetMessages(): void
    {
        $this->flash = null;
        $this->problem = null;
        $this->newPassword = null;
    }

    private function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    private function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
