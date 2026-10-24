<?php

namespace App\Auth;

use App\Models\CustomerAccount;
use App\Tenancy\TenantManager;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bearer tokens for customer accounts. Staff session cookies and staff API tokens are ignored.
 */
final class CustomerTokenGuard implements Guard
{
    use GuardHelpers;

    public function __construct(private Request $request) {}

    public function user(): ?CustomerAccount
    {
        if ($this->user instanceof CustomerAccount) {
            return $this->user;
        }

        $account = $this->accountFromBearer();
        $this->user = $account;

        return $account;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->user = null;
    }

    private function accountFromBearer(): ?CustomerAccount
    {
        $plain = $this->request->bearerToken();

        if (! is_string($plain) || $plain === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plain);
        $account = $accessToken?->tokenable;

        if (! $accessToken instanceof PersonalAccessToken || ! $account instanceof CustomerAccount) {
            return null;
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return null;
        }

        $tenantId = app(TenantManager::class)->id();

        if ($tenantId === null || $account->tenant_id !== $tenantId || $account->email_verified_at === null) {
            return null;
        }

        $account->withAccessToken($accessToken);
        event(new TokenAuthenticated($accessToken));
        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $account;
    }
}
