<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

class CustomerAccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);
        $password = $validated['password'] ?? null;

        $accounts->register(
            $this->tenant($request),
            $validated['email'],
            is_string($validated['name'] ?? null) ? $validated['name'] : null,
            is_string($password) && $password !== '' ? $password : null,
            $request,
        );

        return response()->json(['message' => CustomerAccounts::REQUEST_STATUS]);
    }

    public function sendLink(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $accounts->requestLink($this->tenant($request), $validated['email'], $request);

        return response()->json(['message' => CustomerAccounts::REQUEST_STATUS]);
    }

    public function signIn(Request $request, CustomerAccounts $accounts, string $token): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        return $this->signedIn($accounts, $accounts->consume($token, $validated['email'], $request));
    }

    public function session(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required_without:token', 'prohibits:token', 'string', 'max:255'],
            'token' => ['required_without:password', 'prohibits:password', 'string', 'max:128'],
        ]);
        $token = $validated['token'] ?? null;
        $account = is_string($token)
            ? $accounts->consume($token, $validated['email'], $request)
            : $accounts->login($validated['email'], (string) $validated['password'], $request);

        return $this->signedIn($accounts, $account);
    }

    public function logout(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $account->currentAccessToken()->delete();

        $this->audit->log(
            action: 'customer_auth.sign_out',
            subject: $account,
            properties: ['email' => $account->email],
            request: $request,
        );

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->account($request))]);
    }

    public function password(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);

        $accounts->setPassword($this->account($request), $validated['password'], $request);

        return response()->json(['message' => 'Password saved.']);
    }

    private function signedIn(CustomerAccounts $accounts, CustomerAccount $account): JsonResponse
    {
        return response()->json([
            'token' => $accounts->issueToken($account),
            'token_type' => 'Bearer',
            'account' => $this->payload($account),
        ]);
    }

    /**
     * @return array{id: string, email: string, name: string|null, email_verified_at: string|null}
     */
    private function payload(CustomerAccount $account): array
    {
        $verified = $account->email_verified_at;

        return [
            'id' => $account->id,
            'email' => $account->email,
            'name' => $account->name,
            'email_verified_at' => $verified?->toIso8601String(),
        ];
    }

    private function account(Request $request): CustomerAccount
    {
        $account = $request->user();
        abort_unless($account instanceof CustomerAccount, 401);

        return $account;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
