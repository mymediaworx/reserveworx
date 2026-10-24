<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\RendersStaffPage;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Platform\WorkspaceProvisioner;
use App\Services\Workspaces\WorkspaceDirectory;
use App\Services\Workspaces\WorkspaceSwitch;
use App\Support\Hostname;
use App\Tenancy\Access;
use App\Tenancy\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * An owner adds another business, or moves to one they already belong to.
 * The new business keeps the standard fee defaults. Switching lands on that
 * business's own verified address, where membership is checked again.
 */
class StaffBusinessController extends Controller
{
    use RendersStaffPage;

    public function __construct(
        private readonly WorkspaceDirectory $directory,
        private readonly WorkspaceProvisioner $provisioner,
        private readonly WorkspaceSwitch $switch,
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Request $request): View
    {
        $this->requireOwner($request);

        return $this->staffPage('Add a business', 'staff.business-create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireOwner($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenants,slug'],
            'hostname' => ['nullable', 'string', 'max:253'],
        ]);
        $host = $this->validatedHostname((string) ($validated['hostname'] ?? ''));

        $tenant = $this->provisioner->create($validated['name'], $validated['slug'], $user, $request);
        $this->provisioner->addOwner($tenant, $user, $user, $request);

        if ($host !== null) {
            $this->attachHostname($request, $tenant, $user, $host);
        }

        return redirect('/staff/portfolio?business='.$tenant->id.'&preset=30')
            ->with('status', $tenant->name.' was added. Booking fees stay at the standard rates.');
    }

    public function switch(Request $request, string $tenant): RedirectResponse
    {
        $user = $this->staffUser($request);
        $this->staffTenant($request);
        $destination = Tenant::query()->find($tenant);

        if (! $destination instanceof Tenant || ! $this->access->hasActiveMembership($user, $destination->id)) {
            abort(404);
        }

        if (! in_array($destination->status->value, ['onboarding', 'active'], true)) {
            abort(404);
        }

        $host = $this->directory->verifiedHostname($destination);

        if ($host === null) {
            throw ValidationException::withMessages([
                'switch' => 'That business does not have a verified address yet.',
            ]);
        }

        $plain = $this->switch->mint($user, $destination);

        return redirect()->away($request->getScheme().'://'.$host.'/staff/enter/'.$plain);
    }

    public function enter(Request $request, string $token): RedirectResponse
    {
        $tenant = $this->staffTenant($request);
        $user = $this->switch->consume($token, $tenant);

        if (! $user instanceof User) {
            abort(403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->audit->log(
            action: 'auth.login',
            subject: $user,
            properties: ['channel' => 'staff_switch'],
            actor: $user,
            request: $request,
        );

        return redirect('/staff');
    }

    private function requireOwner(Request $request): User
    {
        $user = $this->staffUser($request);
        $tenant = $this->staffTenant($request);
        abort_unless($this->directory->isOwner($user, $tenant->id), 403);

        return $user;
    }

    private function validatedHostname(string $hostname): ?string
    {
        if (trim($hostname) === '') {
            return null;
        }

        $host = Hostname::normalize($hostname);

        if (! Hostname::isValid($host)) {
            throw ValidationException::withMessages([
                'hostname' => 'Enter a hostname such as booking.example.com.',
            ]);
        }

        if (in_array($host, config('reserveworx.platform_domains', []), true)) {
            throw ValidationException::withMessages([
                'hostname' => 'That hostname is reserved for the platform.',
            ]);
        }

        if (Domain::withoutGlobalScope(TenantScope::class)->where('hostname', $host)->exists()) {
            throw ValidationException::withMessages([
                'hostname' => 'That hostname is already in use.',
            ]);
        }

        return $host;
    }

    private function attachHostname(Request $request, Tenant $tenant, User $actor, string $host): void
    {
        $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'hostname' => $host,
            'type' => 'subdomain',
            'is_primary' => true,
            'verified_at' => null,
        ]);

        $this->audit->log(
            action: 'domain.created',
            subject: $domain,
            properties: ['hostname' => $host, 'type' => 'subdomain'],
            tenantId: $tenant->id,
            actor: $actor,
            request: $request,
            useContextTenant: false,
        );
    }
}
