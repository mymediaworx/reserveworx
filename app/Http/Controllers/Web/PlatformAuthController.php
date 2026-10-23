<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session sign-in for platform administrators on the platform host only.
 */
class PlatformAuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isPlatformAdmin()) {
            return redirect('/platform');
        }

        return view('platform.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = strtolower($validated['email']);
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password) || ! $user->isPlatformAdmin()) {
            $this->audit->log(
                action: 'auth.login_failed',
                properties: ['email' => $email, 'channel' => 'platform_web'],
                request: $request,
                useContextTenant: false,
            );

            throw ValidationException::withMessages([
                'email' => 'That email and password do not match a platform administrator.',
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->log(
            action: 'auth.login',
            subject: $user,
            properties: ['channel' => 'platform_web'],
            actor: $user,
            request: $request,
            useContextTenant: false,
        );

        return redirect()->intended('/platform');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/platform/login');
    }
}
