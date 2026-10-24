<?php

namespace App\Services\Customers;

use App\Models\CustomerAccount;
use App\Models\CustomerSignInToken;
use App\Models\Tenant;
use App\Notifications\CustomerSignInLink;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CustomerAccounts
{
    public const LINK_MINUTES = 60;

    public const REQUEST_STATUS = 'If that email can use an account here, we sent a sign-in link. It expires in 60 minutes.';

    public const INVALID_LINK = 'This sign-in link is invalid or has expired.';

    public const INVALID_CREDENTIALS = 'These credentials do not match our records.';

    public function __construct(
        private readonly CustomerAccountLinker $linker,
        private readonly AuditLogger $audit,
    ) {}

    public function register(Tenant $tenant, string $email, ?string $name, ?string $password, Request $request): void
    {
        $email = strtolower($email);
        $account = $this->find($email);
        $created = false;

        if (! $account instanceof CustomerAccount) {
            try {
                $account = CustomerAccount::query()->create([
                    'tenant_id' => $tenant->id,
                    'email' => $email,
                    'name' => $this->name($name),
                    'password' => $password,
                ]);
                $created = true;
            } catch (QueryException) {
                $account = $this->find($email);
            }
        }

        if (! $account instanceof CustomerAccount) {
            return;
        }

        if ($created) {
            $this->audit->log(
                action: 'customer_auth.registered',
                subject: $account,
                properties: ['email' => $email],
                request: $request,
            );
        }

        $this->sendLink($account, $tenant, $request);
    }

    public function requestLink(Tenant $tenant, string $email, Request $request): void
    {
        $account = $this->find(strtolower($email));

        if (! $account instanceof CustomerAccount) {
            return;
        }

        $this->sendLink($account, $tenant, $request);
    }

    public function consume(string $plainToken, string $email, Request $request): CustomerAccount
    {
        $email = strtolower($email);
        $account = DB::transaction(function () use ($plainToken, $email): ?CustomerAccount {
            $token = CustomerSignInToken::query()
                ->where('token_hash', $this->hash($plainToken))
                ->lockForUpdate()
                ->first();

            if (! $token instanceof CustomerSignInToken || $token->consumed_at !== null || $token->expires_at->isPast()) {
                return null;
            }

            $account = $token->account;

            if (! $account instanceof CustomerAccount || $account->email !== $email) {
                return null;
            }

            $now = now();
            $token->forceFill(['consumed_at' => $now])->save();
            CustomerSignInToken::query()
                ->where('customer_account_id', $account->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            if ($account->email_verified_at === null) {
                $account->forceFill(['email_verified_at' => $now])->save();
            }

            return $account;
        });

        if (! $account instanceof CustomerAccount) {
            $this->audit->log(
                action: 'customer_auth.sign_in_failed',
                properties: ['email' => $email, 'channel' => 'magic_link'],
                request: $request,
            );

            throw ValidationException::withMessages([
                'email' => self::INVALID_LINK,
            ]);
        }

        $account = $this->fresh($account);
        $linked = $this->linker->linkVerifiedAccount($account);

        $this->audit->log(
            action: 'customer_auth.sign_in',
            subject: $account,
            properties: ['email' => $account->email, 'channel' => 'magic_link', 'linked_customers' => $linked],
            request: $request,
        );

        return $this->fresh($account);
    }

    public function login(string $email, string $password, Request $request): CustomerAccount
    {
        $email = strtolower($email);
        $account = $this->find($email);
        $hash = $account?->password;

        if (! $account instanceof CustomerAccount
            || $account->email_verified_at === null
            || ! is_string($hash)
            || $hash === ''
            || ! Hash::check($password, $hash)) {
            $this->audit->log(
                action: 'customer_auth.sign_in_failed',
                properties: ['email' => $email, 'channel' => 'password'],
                request: $request,
            );

            throw ValidationException::withMessages([
                'email' => self::INVALID_CREDENTIALS,
            ]);
        }

        $linked = $this->linker->linkVerifiedAccount($account);

        $this->audit->log(
            action: 'customer_auth.sign_in',
            subject: $account,
            properties: ['email' => $email, 'channel' => 'password', 'linked_customers' => $linked],
            request: $request,
        );

        return $this->fresh($account);
    }

    public function setPassword(CustomerAccount $account, string $password, Request $request): void
    {
        $account->forceFill(['password' => $password])->save();

        $this->audit->log(
            action: 'customer_auth.password_set',
            subject: $account,
            properties: ['email' => $account->email],
            request: $request,
        );
    }

    public function issueToken(CustomerAccount $account): string
    {
        return $account->createToken('customer')->plainTextToken;
    }

    private function find(string $email): ?CustomerAccount
    {
        $account = CustomerAccount::query()->where('email', $email)->first();

        return $account instanceof CustomerAccount ? $account : null;
    }

    private function sendLink(CustomerAccount $account, Tenant $tenant, Request $request): void
    {
        $plain = Str::random(64);
        CustomerSignInToken::query()->create([
            'tenant_id' => $account->tenant_id,
            'customer_account_id' => $account->id,
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addMinutes(self::LINK_MINUTES),
        ]);

        $url = $request->getSchemeAndHttpHost().'/api/v1/customer/sign-in/'.$plain.'?'.http_build_query([
            'email' => $account->email,
        ]);

        try {
            $account->notify(new CustomerSignInLink($url, $tenant->name));
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->audit->log(
            action: 'customer_auth.link_sent',
            subject: $account,
            properties: ['email' => $account->email],
            request: $request,
        );
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function fresh(CustomerAccount $account): CustomerAccount
    {
        $fresh = $account->fresh();

        return $fresh instanceof CustomerAccount ? $fresh : $account;
    }

    private function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }
}
