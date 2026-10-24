<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user('web');
        $tenantId = $this->tenants->id();

        if ($user instanceof User && is_string($tenantId) && $this->access->hasActiveMembership($user, $tenantId)) {
            return redirect()->route('staff.home');
        }

        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $email = strtolower((string) $request->input('email'));
        $user = User::query()->where('email', $email)->first();
        $tenantId = $this->tenants->id();
        $password = (string) $request->input('password');

        if (! $user instanceof User || ! is_string($tenantId) || ! Hash::check($password, $user->password) || ! $this->access->hasActiveMembership($user, $tenantId)) {
            $this->audit->log(
                action: 'auth.login_failed',
                properties: ['email' => $email, 'surface' => 'admin'],
                request: $request,
            );

            return redirect()->route('admin.login')->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->audit->log(
            action: 'auth.login',
            subject: $user,
            actor: $user,
            properties: ['surface' => 'admin'],
            request: $request,
        );

        return redirect()->route('staff.home');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user('web');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->audit->log(
                action: 'auth.logout',
                subject: $user,
                actor: $user,
                properties: ['surface' => 'admin'],
                request: $request,
            );
        }

        return redirect()->route('admin.login');
    }
}
