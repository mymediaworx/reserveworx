<?php

namespace App\Services\Payments;

use App\Domain\Payments\StripeGateway;
use App\Models\PlatformFee;
use App\Models\StripeAccount;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;

/**
 * Compares stored platform fees for a UTC date window with Stripe's application fees.
 */
final class FeeReconciliation
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     stored_gross_cents: int,
     *     stored_refunded_cents: int,
     *     stored_net_cents: int,
     *     stripe_gross_cents: int,
     *     stripe_refunded_cents: int,
     *     stripe_net_cents: int,
     *     balanced: bool,
     *     differences: list<array{
     *         kind: string,
     *         stripe_fee_id: ?string,
     *         platform_fee_id: ?string,
     *         tenant_id: ?string,
     *         stored_amount_cents: ?int,
     *         stored_refunded_cents: ?int,
     *         stripe_amount_cents: ?int,
     *         stripe_refunded_cents: ?int
     *     }>
     * }
     */
    public function build(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from.' 00:00:00', 'UTC');
        $end = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDay();
        $stripeFees = $this->stripe->listApplicationFees($start->getTimestamp(), $end->getTimestamp());

        $local = PlatformFee::withoutGlobalScope(TenantScope::class)
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->get();

        /** @var array<string, array{id: string, amount: int, amount_refunded: int, account: ?string, created: int}> $stripeById */
        $stripeById = [];

        foreach ($stripeFees as $fee) {
            $stripeById[$fee['id']] = $fee;
        }

        $storedGross = 0;
        $storedRefunded = 0;
        $stripeGross = 0;
        $stripeRefunded = 0;
        $matched = [];
        $differences = [];

        foreach ($local as $fee) {
            $amount = (int) $fee->amount_cents;
            $refunded = (int) $fee->refunded_cents;
            $storedGross += $amount;
            $storedRefunded += $refunded;
            $stripeId = $fee->stripe_fee_id;
            $known = is_string($stripeId) && $stripeId !== '' ? ($stripeById[$stripeId] ?? null) : null;

            if ($known === null) {
                $differences[] = $this->difference(
                    'missing_on_stripe',
                    is_string($stripeId) && $stripeId !== '' ? $stripeId : null,
                    $fee->id,
                    $fee->tenant_id,
                    $amount,
                    $refunded,
                    null,
                    null,
                );

                continue;
            }

            $matched[$known['id']] = true;

            if ($amount !== $known['amount'] || $refunded !== $known['amount_refunded']) {
                $differences[] = $this->difference(
                    'amount_mismatch',
                    $known['id'],
                    $fee->id,
                    $fee->tenant_id,
                    $amount,
                    $refunded,
                    $known['amount'],
                    $known['amount_refunded'],
                );
            }
        }

        foreach ($stripeFees as $fee) {
            $stripeGross += $fee['amount'];
            $stripeRefunded += $fee['amount_refunded'];
        }

        $unmatchedAccounts = [];

        foreach ($stripeFees as $fee) {
            if (isset($matched[$fee['id']])) {
                continue;
            }

            if (is_string($fee['account']) && $fee['account'] !== '') {
                $unmatchedAccounts[$fee['account']] = true;
            }
        }

        $tenantsByAccount = $this->tenantsByAccount(array_keys($unmatchedAccounts));

        foreach ($stripeFees as $fee) {
            if (isset($matched[$fee['id']])) {
                continue;
            }

            $account = $fee['account'];
            $tenantId = is_string($account) ? ($tenantsByAccount[$account] ?? null) : null;
            $differences[] = $this->difference(
                'missing_locally',
                $fee['id'],
                null,
                $tenantId,
                null,
                null,
                $fee['amount'],
                $fee['amount_refunded'],
            );
        }

        usort(
            $differences,
            fn (array $left, array $right): int => [
                $left['kind'],
                $left['stripe_fee_id'] ?? '',
                $left['platform_fee_id'] ?? '',
            ] <=> [
                $right['kind'],
                $right['stripe_fee_id'] ?? '',
                $right['platform_fee_id'] ?? '',
            ],
        );

        return [
            'from' => $from,
            'to' => $to,
            'stored_gross_cents' => $storedGross,
            'stored_refunded_cents' => $storedRefunded,
            'stored_net_cents' => $storedGross - $storedRefunded,
            'stripe_gross_cents' => $stripeGross,
            'stripe_refunded_cents' => $stripeRefunded,
            'stripe_net_cents' => $stripeGross - $stripeRefunded,
            'balanced' => $differences === [],
            'differences' => $differences,
        ];
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, string>
     */
    private function tenantsByAccount(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $tenants = [];

        $rows = StripeAccount::withoutGlobalScope(TenantScope::class)
            ->whereIn('stripe_account_id', $accountIds)
            ->get(['stripe_account_id', 'tenant_id']);

        foreach ($rows as $row) {
            if ($row->stripe_account_id !== '' && $row->tenant_id !== '') {
                $tenants[$row->stripe_account_id] = $row->tenant_id;
            }
        }

        return $tenants;
    }

    /**
     * @return array{
     *     kind: string,
     *     stripe_fee_id: ?string,
     *     platform_fee_id: ?string,
     *     tenant_id: ?string,
     *     stored_amount_cents: ?int,
     *     stored_refunded_cents: ?int,
     *     stripe_amount_cents: ?int,
     *     stripe_refunded_cents: ?int
     * }
     */
    private function difference(
        string $kind,
        ?string $stripeFeeId,
        ?string $platformFeeId,
        ?string $tenantId,
        ?int $storedAmount,
        ?int $storedRefunded,
        ?int $stripeAmount,
        ?int $stripeRefunded,
    ): array {
        return [
            'kind' => $kind,
            'stripe_fee_id' => $stripeFeeId,
            'platform_fee_id' => $platformFeeId,
            'tenant_id' => $tenantId,
            'stored_amount_cents' => $storedAmount,
            'stored_refunded_cents' => $storedRefunded,
            'stripe_amount_cents' => $stripeAmount,
            'stripe_refunded_cents' => $stripeRefunded,
        ];
    }
}
