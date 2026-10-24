<?php

namespace App\Http\Controllers\Web;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\SignupAcceptance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Signup\PublicSignup;
use App\Services\Signup\SignupVerification;
use App\Services\Workspaces\WorkspaceDirectory;
use App\Services\Workspaces\WorkspaceSwitch;
use App\Support\SignupTerms;
use App\Support\Subdomain;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public signup lives on the platform host. The new workspace is onboarding
 * until the owner verifies their email and finishes the checklist.
 */
class SignupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Subdomain $subdomains): View
    {
        return view('signup.create', [
            'apex' => $subdomains->apex(),
            'terms' => SignupTerms::body(),
        ]);
    }

    public function availability(Request $request, Subdomain $subdomains): JsonResponse
    {
        $slug = $subdomains->normalize((string) $request->query('slug', ''));
        $status = $subdomains->status($slug);

        return response()->json([
            'slug' => $slug,
            'hostname' => $status === 'invalid' ? null : $subdomains->hostname($slug),
            'status' => $status,
        ]);
    }

    public function store(Request $request, PublicSignup $signup, Subdomain $subdomains): RedirectResponse
    {
        if (trim((string) $request->input('company_url')) !== '') {
            return redirect('/signup/check-email');
        }

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63'],
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
            'accepted_terms' => ['accepted'],
        ], [
            'email.unique' => 'That email already has an account. Sign in to a workspace you own and add a business from there.',
            'accepted_terms.accepted' => 'Accept the terms to create a workspace.',
        ]);
        $slug = $subdomains->normalize($validated['slug']);
        $status = $subdomains->status($slug);

        if ($status === 'invalid') {
            throw ValidationException::withMessages([
                'slug' => 'Use 3 to 63 characters: lowercase letters, numbers, and hyphens.',
            ]);
        }

        if ($status === 'reserved') {
            throw ValidationException::withMessages([
                'slug' => 'That address is reserved.',
            ]);
        }

        if ($status === 'taken') {
            throw ValidationException::withMessages([
                'slug' => 'That address is already taken.',
            ]);
        }

        try {
            $signup->register(
                businessName: $validated['business_name'],
                slug: $slug,
                ownerName: $validated['owner_name'],
                email: strtolower($validated['email']),
                password: $validated['password'],
                request: $request,
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            $field = str_contains($exception->getMessage(), 'users_email') ? 'email' : 'slug';
            $message = $field === 'email'
                ? 'That email already has an account. Sign in to a workspace you own and add a business from there.'
                : 'That address is already taken.';

            throw ValidationException::withMessages([$field => $message]);
        }

        return redirect('/signup/check-email')
            ->with('status', 'If this is a new workspace, we sent a verification link. It expires in 60 minutes.');
    }

    public function checkEmail(): View
    {
        return view('signup.check-email');
    }

    public function resend(Request $request, SignupVerification $verification): RedirectResponse
    {
        $email = strtolower((string) $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User && $user->email_verified_at === null) {
            $acceptance = SignupAcceptance::withoutGlobalScope(TenantScope::class)
                ->where('user_id', $user->id)
                ->first();
            $tenant = $acceptance instanceof SignupAcceptance
                ? Tenant::query()->find($acceptance->tenant_id)
                : null;

            if ($tenant instanceof Tenant) {
                $verification->send($user, $tenant);
            }
        }

        return back()->with('status', 'If that signup is waiting on verification, we sent another link. It expires in 60 minutes.');
    }

    public function verify(
        Request $request,
        string $user,
        string $tenant,
        string $hash,
        WorkspaceDirectory $directory,
        WorkspaceSwitch $switch,
    ): RedirectResponse {
        $account = User::query()->find($user);
        abort_unless($account instanceof User, 404);
        abort_unless(hash_equals(sha1($account->email), $hash), 403);

        $workspace = Tenant::query()->find($tenant);
        abort_unless($workspace instanceof Tenant, 404);
        abort_unless(in_array($workspace->status, [TenantStatus::Onboarding, TenantStatus::Active], true), 404);

        $acceptance = SignupAcceptance::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace->id)
            ->where('user_id', $account->id)
            ->first();
        abort_unless($acceptance instanceof SignupAcceptance, 404);

        if ($account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();
            $this->audit->log(
                action: 'auth.email_verified',
                subject: $account,
                properties: ['channel' => 'signup'],
                tenantId: $workspace->id,
                actor: $account,
                request: $request,
                useContextTenant: false,
            );
        }

        $host = $directory->verifiedHostname($workspace);
        abort_unless(is_string($host) && $host !== '', 404);

        $plain = $switch->mint($account, $workspace);

        return redirect()->away($request->getScheme().'://'.$host.'/staff/enter/'.$plain);
    }

    private function isDuplicate(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        return $state === '23000' || (string) $exception->getCode() === '23000';
    }
}
