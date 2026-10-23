<?php

namespace App\Domain\Payments;

/**
 * Accounts v2 request for a SaaS direct-charge merchant.
 *
 * Stripe collects processing fees and losses. The connected account gets the
 * full Dashboard. There is no customer configuration because the platform
 * does not charge a subscription.
 */
final class ConnectAccountSpec
{
    public const API_VERSION = '2026-09-30.preview';

    /**
     * @return array<string, mixed>
     */
    public static function merchant(string $tenantId, string $displayName, string $email): array
    {
        return [
            'display_name' => $displayName,
            'contact_email' => $email,
            'dashboard' => 'full',
            'metadata' => ['tenant_id' => $tenantId],
            'configuration' => [
                'merchant' => [
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                    ],
                ],
            ],
            'defaults' => [
                'currency' => 'usd',
                'responsibilities' => [
                    'fees_collector' => 'stripe',
                    'losses_collector' => 'stripe',
                ],
            ],
            'include' => ['configuration.merchant'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function onboardingLink(string $accountId, string $refreshUrl, string $returnUrl): array
    {
        return [
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public static function cardPaymentsStatus(array $account): string
    {
        return self::readCardPaymentsStatus($account) ?? 'pending';
    }

    /**
     * Null when the payload does not carry the capability. Callers must not
     * treat a missing field as "pending", or an unrelated account event would
     * wipe a status that was already active.
     *
     * @param  array<string, mixed>  $account
     */
    public static function readCardPaymentsStatus(array $account): ?string
    {
        $configuration = $account['configuration'] ?? null;
        $merchant = is_array($configuration) ? ($configuration['merchant'] ?? null) : null;
        $capabilities = is_array($merchant) ? ($merchant['capabilities'] ?? null) : null;
        $cards = is_array($capabilities) ? ($capabilities['card_payments'] ?? null) : null;
        $status = is_array($cards) ? ($cards['status'] ?? null) : null;

        return is_string($status) && $status !== '' ? $status : null;
    }
}
