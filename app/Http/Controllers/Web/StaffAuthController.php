<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PasswordResets;
use App\Tenancy\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for staff on a tenant domain. The session cookie is host-only,
 * so signing in on one workspace's domain never signs you in anywhere else.
 */
class StaffAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Access $access,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tenant = $this->tenant($request);

        if ($user instanceof User && $this->access->hasActiveMembership($user, $tenant->id)) {
            return redirect('/staff');
        }

        return view('staff.auth.login', ['tenant' => $tenant]);
    }

    public function login(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = strtolower($validated['email']);
        $user = User::query()->where('email', $email)->first();

        // One message for a wrong password and for a valid user with no seat here,
        // so the form does not reveal who works where.
        if (! $user instanceof User
            || ! Hash::check($validated['password'], $user->password)
            || ! $this->access->hasActiveMembership($user, $tenant->id)) {
            $this->audit->log(
                action: 'auth.login_failed',
                properties: ['email' => $email, 'channel' => 'web'],
                request: $request,
            );

            throw ValidationException::withMessages([
                'email' => 'That email and password do not match a team member of this workspace.',
            ]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->audit->log(
            action: 'auth.login',
            subject: $user,
            properties: ['channel' => 'web'],
            actor: $user,
            request: $request,
        );

        return redirect()->intended('/staff');
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->audit->log(
                action: 'auth.logout',
                subject: $user,
                properties: ['channel' => 'web'],
                actor: $user,
                request: $request,
            );
        }

        return redirect('/staff/login');
    }

    public function requestForm(Request $request): View
    {
        return view('staff.auth.forgot', ['tenant' => $this->tenant($request)]);
    }

    public function sendReset(Request $request, PasswordResets $resets): RedirectResponse
    {
        $email = strtolower((string) $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']);
        $tenant = $this->tenant($request);
        $resets->request(
            $email,
            fn (User $user): bool => $this->access->hasActiveMembership($user, $tenant->id),
            '/staff/reset-password',
        );

        return back()->with('status', PasswordResets::REQUEST_STATUS);
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('staff.auth.reset', [
            'tenant' => $this->tenant($request),
            'token' => $token,
            'email' => strtolower((string) $request->query('email', '')),
        ]);
    }

    public function resetPassword(Request $request, PasswordResets $resets): RedirectResponse
    {
        /** @var array{email: string, password: string, password_confirmation: string, token: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);
        $validated['email'] = strtolower($validated['email']);
        $tenant = $this->tenant($request);
        $status = $resets->reset(
            $validated,
            fn (User $user): bool => $this->access->hasActiveMembership($user, $tenant->id),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => PasswordResets::INVALID_STATUS,
            ]);
        }

        $this->audit->log(
            action: 'auth.password_reset',
            properties: ['email' => $validated['email'], 'channel' => 'web'],
            request: $request,
        );

        return redirect('/staff/login')->with('status', 'Your password has been reset. Sign in with the new one.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
