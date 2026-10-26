<?php

namespace App\Livewire\Platform;

use App\Enums\Industry;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDataExport;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integrations\GhlConnectionStore;
use App\Services\Integrations\GhlRequestFailed;
use App\Services\Integrations\GhlSyncLogger;
use App\Services\Integrations\GoHighLevelClient;
use App\Services\Platform\TenantExporter;
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

    public string $industry = 'boat_rentals';

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

    public string $ghlLocationId = '';

    /**
     * The token typed into the settings form. Cleared before the response so
     * it is not stored in the Livewire snapshot or written to a log.
     */
    public string $ghlToken = '';

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(string $tenant): void
    {
        $this->guard();
        $model = $this->tenant($tenant);
        $this->tenantId = $model->id;
        $this->industry = $model->industry->value;
        $setting = $this->setting();
        $this->directPercent = $this->percent($setting->direct_fee_bps);
        $this->thirdPartyPercent = $this->percent($setting->third_party_fee_bps);
        $this->directFixed = $this->dollars($setting->direct_fee_fixed_cents);
        $this->thirdPartyFixed = $this->dollars($setting->third_party_fee_fixed_cents);
        $this->directMinimum = $setting->direct_fee_minimum_cents === null ? '' : $this->dollars($setting->direct_fee_minimum_cents);
        $this->thirdPartyMinimum = $setting->third_party_fee_minimum_cents === null ? '' : $this->dollars($setting->third_party_fee_minimum_cents);
        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)->where('tenant_id', $model->id)->first();
        $this->ghlLocationId = $connection instanceof GhlConnection ? $connection->location_id : '';
        $this->ghlToken = '';
    }

    public function setStatus(string $status, TenantLifecycle $lifecycle, AuditLogger $audit, TenantExporter $exports): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $tenant = $this->tenant($this->tenantId);
        $next = TenantStatus::tryFrom($status);
        abort_if($next === null, 422);

        if ($next === TenantStatus::Terminated && ! $exports->isReady($tenant)) {
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

    public function saveIndustry(AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $this->validate([
            'industry' => ['required', 'string', Rule::enum(Industry::class)],
        ]);
        $tenant = $this->tenant($this->tenantId);
        $next = Industry::from($this->industry);

        if ($tenant->industry === $next) {
            $this->flash = 'Industry is already '.$next->label().'.';

            return;
        }

        $before = $tenant->industry->value;
        $tenant->industry = $next;
        $tenant->save();
        $audit->log('tenant.industry_updated', $tenant, ['before' => $before, 'after' => $next->value], $tenant->id, $actor, request(), false);
        $this->flash = 'Industry changed to '.$next->label().'.';
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

    public function saveGhl(GhlConnectionStore $connections): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $locationId = trim($this->ghlLocationId);
        $token = $this->ghlToken;
        $this->ghlToken = '';
        $existing = $connections->find($this->tenant($this->tenantId));

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $locationId)) {
            $this->addError('ghlLocationId', 'Enter the Location ID from the BrandWorx sub-account.');
        }

        if (! $existing instanceof GhlConnection && $token === '') {
            $this->addError('ghlToken', 'Enter the Private Integration Token from that sub-account.');
        } elseif ($token !== '' && (strlen($token) < 20 || strlen($token) > 2000)) {
            $this->addError('ghlToken', 'That token is too short.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $connections->link($this->tenant($this->tenantId), $locationId, $token !== '' ? $token : null, $actor, request());
        $this->ghlLocationId = $locationId;
        $this->flash = 'GoHighLevel sub-account saved.';
    }

    public function removeGhl(GhlConnectionStore $connections): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $this->ghlToken = '';
        $connections->unlink($this->tenant($this->tenantId), $actor, request());
        $this->ghlLocationId = '';
        $this->flash = 'GoHighLevel sub-account removed.';
    }

    public function testGhl(GhlConnectionStore $connections, GoHighLevelClient $client, GhlSyncLogger $logger): void
    {
        $this->guard();
        $this->resetMessages();
        $this->ghlToken = '';
        $connection = $connections->find($this->tenant($this->tenantId));

        if (! $connection instanceof GhlConnection) {
            $this->problem = 'GoHighLevel is not linked.';

            return;
        }

        $token = $connection->private_integration_token;

        try {
            $client->assertLocation($token, $connection->location_id);
        } catch (GhlRequestFailed $exception) {
            $logger->write(
                tenantId: $this->tenantId,
                kind: 'connection.test',
                attempt: 1,
                status: 'failed',
                locationId: $connection->location_id,
                bookingId: null,
                paymentId: null,
                httpStatus: $exception->httpStatus,
                error: $exception->getMessage(),
                secret: $token,
            );
            $this->problem = 'GoHighLevel refused the connection.';

            return;
        }

        $logger->write(
            tenantId: $this->tenantId,
            kind: 'connection.test',
            attempt: 1,
            status: 'succeeded',
            locationId: $connection->location_id,
            bookingId: null,
            paymentId: null,
            httpStatus: null,
            error: null,
            secret: $token,
        );
        $this->flash = 'GoHighLevel accepted this location.';
    }

    public function exportData(TenantExporter $exports): void
    {
        $actor = $this->guard();
        $this->resetMessages();
        $export = $exports->create($this->tenant($this->tenantId), $actor, request());
        $this->flash = 'Export saved ('.number_format($export->bytes).' bytes). Download it below. Closing for good is now allowed.';
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
            'industries' => Industry::cases(),
            'setting' => $this->setting(),
            'ghlConnection' => GhlConnection::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first(),
            'ghlMessages' => GhlMessageLog::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'exports' => TenantDataExport::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('completed_at')
                ->get(),
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
