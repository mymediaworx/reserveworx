<?php

namespace App\Services\Payments;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Models\StripeAccount;
use App\Models\StripeConnectRequest;
use App\Models\Tenant;
use App\Services\Allocation\IdempotencyConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Opens one Stripe Connect onboarding link for a workspace.
 * A repeated key returns the stored account and does not call Stripe again.
 * The onboarding URL is not stored.
 */
final class StripeConnectRequests
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @param  array{display_name: string, email: string, refresh_url: string, return_url: string}  $input
     * @return array{account: StripeAccount, onboarding_url: string|null, replayed: bool}
     */
    public function open(Tenant $tenant, array $input, ?string $key): array
    {
        if ($key === null) {
            return $this->link($tenant, $input);
        }

        $hash = $this->hash($input);
        $lock = substr(hash('sha256', $tenant->id.'|stripe_connect|'.$key), 0, 64);
        $acquired = DB::scalar('select get_lock(?, 10)', [$lock]);

        if ((int) $acquired !== 1) {
            throw new IdempotencyConflictException;
        }

        try {
            return $this->once($tenant, $input, $key, $hash);
        } finally {
            DB::scalar('select release_lock(?)', [$lock]);
        }
    }

    /**
     * @param  array{display_name: string, email: string, refresh_url: string, return_url: string}  $input
     * @return array{account: StripeAccount, onboarding_url: string|null, replayed: bool}
     */
    private function once(Tenant $tenant, array $input, string $key, string $hash): array
    {
        $existing = $this->stored($key);

        if ($existing instanceof StripeConnectRequest) {
            return $this->replay($existing, $tenant, $hash);
        }

        $opened = $this->link($tenant, $input);

        try {
            StripeConnectRequest::query()->create([
                'tenant_id' => $tenant->id,
                'account_id' => $opened['account']->id,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $winner = $this->stored($key);

            if (! $winner instanceof StripeConnectRequest) {
                throw $exception;
            }

            return $this->replay($winner, $tenant, $hash);
        }

        return $opened;
    }

    /**
     * @return array{account: StripeAccount, onboarding_url: null, replayed: true}
     */
    private function replay(StripeConnectRequest $existing, Tenant $tenant, string $hash): array
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyConflictException;
        }

        $account = StripeAccount::query()->whereKey($existing->account_id)->first();

        if (! $account instanceof StripeAccount || $account->tenant_id !== $tenant->id) {
            throw new IdempotencyConflictException;
        }

        return [
            'account' => $account,
            'onboarding_url' => null,
            'replayed' => true,
        ];
    }

    /**
     * @param  array{display_name: string, email: string, refresh_url: string, return_url: string}  $input
     * @return array{account: StripeAccount, onboarding_url: string, replayed: false}
     */
    private function link(Tenant $tenant, array $input): array
    {
        $account = StripeAccount::query()->where('tenant_id', $tenant->id)->first();

        if (! $account instanceof StripeAccount) {
            $created = $this->stripe->createMerchantAccount(ConnectAccountSpec::merchant(
                $tenant->id,
                $input['display_name'],
                $input['email'],
            ));
            $account = StripeAccount::query()->create([
                'tenant_id' => $tenant->id,
                'stripe_account_id' => (string) $created['id'],
                'card_payments_status' => (string) $created['card_payments_status'],
                'livemode' => (bool) $created['livemode'],
            ]);
        }

        $link = $this->stripe->createOnboardingLink(ConnectAccountSpec::onboardingLink(
            $account->stripe_account_id,
            $input['refresh_url'],
            $input['return_url'],
        ));

        return [
            'account' => $account,
            'onboarding_url' => (string) $link['url'],
            'replayed' => false,
        ];
    }

    private function stored(string $key): ?StripeConnectRequest
    {
        $existing = StripeConnectRequest::query()->where('idempotency_key', $key)->first();

        return $existing instanceof StripeConnectRequest ? $existing : null;
    }

    /**
     * @param  array{display_name: string, email: string, refresh_url: string, return_url: string}  $input
     */
    private function hash(array $input): string
    {
        try {
            $encoded = json_encode([
                'display_name' => $input['display_name'],
                'email' => $input['email'],
                'refresh_url' => $input['refresh_url'],
                'return_url' => $input['return_url'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encoded = '';
        }

        return hash('sha256', $encoded);
    }
}
