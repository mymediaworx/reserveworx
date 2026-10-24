<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\InviteClosed;
use App\Services\Team\WorkspaceTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * The signed invitation link. A new person chooses a password. Someone who
 * already has an account joins this workspace and keeps that password.
 */
class StaffInviteController extends Controller
{
    public function show(Request $request, Membership $membership, WorkspaceTeam $team): Response
    {
        $token = (string) $request->query('token', '');

        if (! $team->invitationIsOpen($membership, $token)) {
            return $this->invalid($request);
        }

        $membership->load(['user', 'role']);
        $session = $request->user();

        return response()->view('staff.invite', [
            'tenant' => $this->tenant($request),
            'membership' => $membership,
            'needsPassword' => $team->needsPassword($membership),
            'blocked' => $session instanceof User && $session->id !== $membership->user_id,
            'roleName' => $membership->role->name,
        ]);
    }

    public function accept(Request $request, Membership $membership, WorkspaceTeam $team): Response|RedirectResponse
    {
        $token = (string) $request->query('token', '');

        if (! $team->invitationIsOpen($membership, $token)) {
            return $this->invalid($request);
        }

        $membership->load('user');
        $needsPassword = $team->needsPassword($membership);
        $name = null;
        $password = null;

        if ($needsPassword) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
            ]);
            $name = $validated['name'];
            $password = $validated['password'];
        }

        try {
            $account = $team->accept($membership, $token, $request, $name, $password);
        } catch (InviteClosed) {
            return $this->invalid($request);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        if ($needsPassword) {
            Auth::guard('web')->login($account);
            $request->session()->regenerate();

            return redirect('/staff');
        }

        if ($request->user()?->is($account)) {
            return redirect('/staff');
        }

        return redirect('/staff/login')->with('status', 'You are on the team. Sign in with your password.');
    }

    private function invalid(Request $request): Response
    {
        return response()->view('staff.invite-invalid', [
            'tenant' => $this->tenant($request),
        ], 403);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
